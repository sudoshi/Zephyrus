"""Provider-neutral chat adapters for Eddy (Ollama local + Anthropic frontier).

Ported/trimmed from Abby's chat_adapters.py. The OpenAI / OpenAI-compatible
adapters are intentionally omitted from the v1 surface (add later). `anthropic`
is imported lazily so the Ollama path and the unit tests run without it.
"""

from __future__ import annotations

import json
import hashlib
import time
from dataclasses import dataclass, field
from typing import Any, AsyncGenerator, cast

import httpx

from app.routing.profiles import ProviderProfile, estimate_cost


@dataclass(frozen=True)
class ChatAdapterRequest:
    system_prompt: str
    message: str
    history: list[dict[str, str]] = field(default_factory=list)
    temperature: float = 0.1
    max_output_tokens: int | None = None


@dataclass(frozen=True)
class ChatAdapterResponse:
    reply: str
    provider: str
    transport: str
    model: str
    tokens_in: int = 0
    tokens_out: int = 0
    cost_usd: float = 0.0
    latency_ms: float = 0.0
    request_hash: str = ""
    raw: dict[str, Any] = field(default_factory=dict)


@dataclass(frozen=True)
class ChatStreamEvent:
    kind: str
    token: str = ""
    payload: dict[str, Any] = field(default_factory=dict)


class ChatAdapterError(RuntimeError):
    """Classified provider failure suitable for route-fallback metadata."""

    def __init__(self, message: str, *, error_class: str, status_code: int = 503, retryable: bool = False) -> None:
        super().__init__(message)
        self.error_class = error_class
        self.status_code = status_code
        self.retryable = retryable


class OllamaChatAdapter:
    """Ollama `/api/chat` adapter for the local MedGemma chat path."""

    def __init__(
        self,
        *,
        profile: ProviderProfile,
        client: httpx.AsyncClient,
        default_num_predict: int = 256,
        keep_alive_seconds: int = 3600,
        timeout_seconds: int = 60,
        max_retries: int = 2,
    ) -> None:
        self.profile = profile
        self.client = client
        self.default_num_predict = default_num_predict
        self.keep_alive_seconds = keep_alive_seconds
        self.timeout_seconds = timeout_seconds
        self.max_retries = max_retries

    def _messages(self, request: ChatAdapterRequest) -> list[dict[str, str]]:
        messages = [{"role": "system", "content": request.system_prompt}]
        messages.extend(request.history[-10:])
        messages.append({"role": "user", "content": request.message})
        return messages

    async def chat(self, request: ChatAdapterRequest) -> ChatAdapterResponse:
        started = time.perf_counter()
        messages = self._messages(request)
        num_predict = request.max_output_tokens or self.default_num_predict
        base_url = (self.profile.base_url or "").rstrip("/")

        for attempt in range(self.max_retries + 1):
            # Cold model loads can take much longer than steady-state inference.
            attempt_timeout = 180 if attempt == 0 else min(60, self.timeout_seconds)
            try:
                response = await self.client.post(
                    f"{base_url}/api/chat",
                    json={
                        "model": self.profile.model,
                        "messages": messages,
                        "stream": False,
                        "think": False,
                        "keep_alive": self.keep_alive_seconds,
                        "options": {"temperature": request.temperature, "num_predict": num_predict},
                    },
                    timeout=attempt_timeout,
                )
                response.raise_for_status()
                data = response.json()
                reply = data.get("message", {}).get("content", "")
                return ChatAdapterResponse(
                    reply=reply,
                    provider=self.profile.provider,
                    transport=self.profile.transport,
                    model=self.profile.model,
                    tokens_in=int(data.get("prompt_eval_count", 0) or 0),
                    tokens_out=int(data.get("eval_count", 0) or 0),
                    latency_ms=round((time.perf_counter() - started) * 1000, 2),
                    raw=data,
                )
            except httpx.TimeoutException as exc:
                if attempt < self.max_retries:
                    continue
                raise ChatAdapterError("LLM service timed out after retries.", error_class="timeout", status_code=504, retryable=True) from exc
            except httpx.HTTPStatusError as exc:
                if exc.response.status_code == 500 and attempt < self.max_retries:
                    continue
                raise ChatAdapterError(
                    f"LLM service error: {exc}",
                    error_class=_classify_http_status(exc.response.status_code),
                    retryable=exc.response.status_code in {408, 429, 500, 502, 503, 504},
                ) from exc
            except ChatAdapterError:
                raise
            except Exception as exc:
                raise ChatAdapterError(f"LLM service unavailable: {exc}", error_class="provider_unavailable", retryable=True) from exc

        raise ChatAdapterError("LLM service unavailable: all retries exhausted", error_class="provider_unavailable", retryable=True)

    async def stream(self, request: ChatAdapterRequest) -> AsyncGenerator[ChatStreamEvent, None]:
        started = time.perf_counter()
        messages = self._messages(request)
        num_predict = request.max_output_tokens or self.default_num_predict
        base_url = (self.profile.base_url or "").rstrip("/")
        full_content = ""
        try:
            async with self.client.stream(
                "POST",
                f"{base_url}/api/chat",
                json={
                    "model": self.profile.model,
                    "messages": messages,
                    "stream": True,
                    "think": False,
                    "keep_alive": self.keep_alive_seconds,
                    "options": {"temperature": request.temperature, "num_predict": num_predict},
                },
                timeout=self.timeout_seconds,
            ) as response:
                response.raise_for_status()
                async for line in response.aiter_lines():
                    if not line.strip():
                        continue
                    try:
                        data = cast(dict[str, Any], json.loads(line))
                    except json.JSONDecodeError:
                        continue
                    if data.get("done"):
                        break
                    token = str(data.get("message", {}).get("content", ""))
                    if token:
                        full_content += token
                        yield ChatStreamEvent(kind="token", token=token)
        except httpx.TimeoutException as exc:
            raise ChatAdapterError("LLM service timed out.", error_class="timeout", status_code=504, retryable=True) from exc
        except httpx.HTTPStatusError as exc:
            raise ChatAdapterError(f"LLM service error: {exc}", error_class=_classify_http_status(exc.response.status_code), retryable=True) from exc
        except ChatAdapterError:
            raise
        except Exception as exc:
            raise ChatAdapterError(f"LLM service unavailable: {exc}", error_class="provider_unavailable", retryable=True) from exc

        yield ChatStreamEvent(
            kind="complete",
            payload={"full_content": full_content, "model": self.profile.model, "latency_ms": round((time.perf_counter() - started) * 1000, 2)},
        )


class AnthropicMessagesAdapter:
    """Anthropic Messages adapter (current SDK). Opus-safe param handling."""

    def __init__(self, *, profile: ProviderProfile, api_key: str, max_output_tokens: int = 4096, timeout_seconds: int = 60) -> None:
        if not api_key:
            raise ValueError("Anthropic API key must not be empty")
        self.profile = profile
        self.api_key = api_key
        self.max_output_tokens = max_output_tokens
        self.timeout_seconds = timeout_seconds

    def _build_kwargs(self, request: ChatAdapterRequest) -> dict[str, Any]:
        history = [
            {"role": t["role"], "content": t["content"]}
            for t in request.history[-10:]
            if t.get("role") in {"user", "assistant"} and t.get("content")
        ]
        kwargs: dict[str, Any] = {
            "model": self.profile.model,
            "max_tokens": request.max_output_tokens or self.max_output_tokens,
            "system": request.system_prompt,
            "messages": [*history, {"role": "user", "content": request.message}],
        }
        # temperature/top_p/top_k are removed on Opus 4.8 → 400. Only Sonnet/Haiku accept it.
        if not self.profile.model.startswith("claude-opus"):
            kwargs["temperature"] = request.temperature
        return kwargs

    async def chat(self, request: ChatAdapterRequest) -> ChatAdapterResponse:
        import anthropic  # lazy

        started = time.perf_counter()
        request_hash = _compute_request_hash(request.system_prompt, request.history, request.message)
        try:
            client = anthropic.AsyncAnthropic(api_key=self.api_key, timeout=self.timeout_seconds)
            resp = await client.messages.create(**self._build_kwargs(request))
        except Exception as exc:
            raise _adapter_error_from_exception(exc) from exc

        reply = "".join(getattr(b, "text", "") for b in getattr(resp, "content", []) if getattr(b, "type", "") == "text")
        usage = getattr(resp, "usage", None)
        tokens_in = int(getattr(usage, "input_tokens", 0) or 0)
        tokens_out = int(getattr(usage, "output_tokens", 0) or 0)
        model = str(getattr(resp, "model", self.profile.model))
        return ChatAdapterResponse(
            reply=reply,
            provider=self.profile.provider,
            transport=self.profile.transport,
            model=model,
            tokens_in=tokens_in,
            tokens_out=tokens_out,
            cost_usd=estimate_cost(tokens_in=tokens_in, tokens_out=tokens_out, model=model),
            latency_ms=round((time.perf_counter() - started) * 1000, 2),
            request_hash=request_hash,
        )

    async def stream(self, request: ChatAdapterRequest) -> AsyncGenerator[ChatStreamEvent, None]:
        import anthropic  # lazy

        request_hash = _compute_request_hash(request.system_prompt, request.history, request.message)
        full_content = ""
        model = self.profile.model
        tokens_in = tokens_out = 0
        try:
            client = anthropic.AsyncAnthropic(api_key=self.api_key, timeout=self.timeout_seconds)
            async with client.messages.stream(**self._build_kwargs(request)) as stream:
                async for text in stream.text_stream:
                    if text:
                        full_content += text
                        yield ChatStreamEvent(kind="token", token=text)
                final = await stream.get_final_message()
                model = str(getattr(final, "model", model))
                usage = getattr(final, "usage", None)
                tokens_in = int(getattr(usage, "input_tokens", 0) or 0)
                tokens_out = int(getattr(usage, "output_tokens", 0) or 0)
        except Exception as exc:
            err = _adapter_error_from_exception(exc)
            yield ChatStreamEvent(kind="error", payload={"message": str(exc), "error_class": err.error_class})
            return

        yield ChatStreamEvent(
            kind="complete",
            payload={
                "full_content": full_content,
                "model": model,
                "tokens_in": tokens_in,
                "tokens_out": tokens_out,
                "cost_usd": estimate_cost(tokens_in=tokens_in, tokens_out=tokens_out, model=model),
                "request_hash": request_hash,
            },
        )


def _compute_request_hash(system_prompt: str, history: list[dict[str, str]], message: str) -> str:
    hasher = hashlib.sha256()
    hasher.update(system_prompt.encode())
    for turn in history[-10:]:
        hasher.update(str(turn.get("role", "")).encode())
        hasher.update(str(turn.get("content", "")).encode())
    hasher.update(b"user")
    hasher.update(message.encode())
    return hasher.hexdigest()


def _adapter_error_from_exception(exc: Exception) -> ChatAdapterError:
    status_code = int(getattr(exc, "status_code", 0) or 0)
    error_class = _classify_http_status(status_code) if status_code else classify_provider_error(exc)
    return ChatAdapterError(
        str(exc) or error_class,
        error_class=error_class,
        status_code=504 if status_code == 504 else 503,
        retryable=error_class in {"provider_rate_limited", "timeout", "provider_unavailable"},
    )


def _classify_http_status(status_code: int) -> str:
    if status_code in {401, 403}:
        return "invalid_key"
    if status_code == 429:
        return "provider_rate_limited"
    if status_code in {402, 409}:
        return "provider_quota_exhausted"
    if status_code in {408, 504}:
        return "timeout"
    if status_code >= 500:
        return "provider_unavailable"
    return "provider_error"


def classify_provider_error(exc: Exception) -> str:
    text = f"{exc.__class__.__name__} {exc}".lower()
    if "safety" in text or "refusal" in text or "content policy" in text:
        return "provider_safety_refusal"
    if "rate" in text and "limit" in text:
        return "provider_rate_limited"
    if "quota" in text or "credit" in text or "billing" in text or "insufficient" in text:
        return "provider_quota_exhausted"
    if "auth" in text or "api key" in text or "permission" in text or "forbidden" in text:
        return "invalid_key"
    if "timeout" in text or "timed out" in text:
        return "timeout"
    if "model" in text and ("not found" in text or "unavailable" in text):
        return "model_unavailable"
    return "provider_error"
