from app.routing.router import build_system_prompt, extract_proposed_action


def test_extracts_a_valid_proposal_and_strips_the_block():
    reply = (
        "Imaging delays are holding two 3W discharges.\n"
        '<propose_action>{"action_type":"flag_barrier","title":"Imaging delay on 3W",'
        '"params":{"unit":"3W","barrier":"imaging"},"rationale":"two discharges held",'
        '"runner_up":"escalate to radiology charge"}</propose_action>'
    )
    clean, action = extract_proposed_action(reply)
    assert "propose_action" not in clean
    assert "Imaging delays" in clean
    assert action is not None
    assert action["action_type"] == "flag_barrier"
    assert action["params"]["unit"] == "3W"


def test_no_block_means_no_action():
    clean, action = extract_proposed_action("Here is an explanation with no action.")
    assert action is None
    assert clean == "Here is an explanation with no action."


def test_malformed_json_is_ignored():
    clean, action = extract_proposed_action("text <propose_action>{not valid json}</propose_action>")
    assert action is None


def test_missing_action_type_is_dropped():
    clean, action = extract_proposed_action('x <propose_action>{"title":"no type"}</propose_action>')
    assert action is None
    assert "propose_action" not in clean


def test_action_protocol_lists_allowed_types_in_the_prompt():
    prompt = build_system_prompt("rtdc", None, None, allowed_actions=["flag_barrier", "propose_surge_plan"])
    assert "ACTION PROPOSALS" in prompt
    assert "flag_barrier" in prompt
    assert "propose_surge_plan" in prompt
    assert "approve" in prompt
