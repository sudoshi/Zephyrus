from app.routing.router import build_system_prompt


def test_live_context_is_injected_into_the_prompt():
    prompt = build_system_prompt(
        "rtdc",
        "RTDC/BedTracking",
        {"roles": ["bed_manager"]},
        live_context={
            "capacity": {"available_beds": 4, "occupied_beds": 480, "blocked_beds": 6, "net_beds": -12, "pending_admits": 16, "ed_boarders": 3, "transport_at_risk": 2, "risk_score": 78},
            "source_freshness": {"status": "success", "census_lag_minutes": 10},
            "findings": [{"key": "net_bed_deficit", "status": "critical", "detail": "-12 net beds after pending admits."}],
        },
        knowledge=[{"title": "Red stretch / surge escalation", "body": "Open a surge plan…", "source": "internal-policy"}],
    )
    assert "LIVE OPERATIONS DATA" in prompt
    assert "-12" in prompt
    assert "3 ED boarders" in prompt
    assert "KNOWLEDGE BASE" in prompt
    assert "Red stretch" in prompt
    assert "bed_manager" in prompt


def test_stale_census_adds_a_data_trust_caveat():
    prompt = build_system_prompt(
        "rtdc",
        None,
        None,
        live_context={"source_freshness": {"status": "warning", "census_lag_minutes": 90}, "capacity": {}},
    )
    assert "DATA TRUST CAUTION" in prompt
    assert "90 min old" in prompt


def test_missing_timestamp_is_flagged():
    prompt = build_system_prompt(
        "chat", None, None,
        live_context={"source_freshness": {"status": "warning", "census_lag_minutes": None}, "capacity": {}},
    )
    assert "DATA TRUST CAUTION" in prompt
    assert "no timestamped census" in prompt


def test_no_context_yields_no_blocks():
    prompt = build_system_prompt("chat", None, None)
    assert "LIVE OPERATIONS DATA" not in prompt
    assert "KNOWLEDGE BASE" not in prompt


def test_learned_preferences_are_injected():
    prompt = build_system_prompt(
        "rtdc",
        None,
        {
            "roles": ["charge_nurse"],
            "preferences": {
                "preferred_actions": ["flag_barrier"],
                "discouraged_actions": ["propose_bed_placement"],
            },
        },
    )
    assert "LEARNED PREFERENCES" in prompt
    assert "flag_barrier" in prompt
    assert "propose_bed_placement" in prompt


def test_empty_preferences_yield_no_block():
    prompt = build_system_prompt("rtdc", None, {"roles": ["charge_nurse"], "preferences": {}})
    assert "LEARNED PREFERENCES" not in prompt
