from app.routing.rule_router import RuleRouter

r = RuleRouter()


def test_greeting_routes_local():
    assert r.route("hi eddy").model == "local"


def test_action_word_routes_cloud():
    assert r.route("create a surge plan for 4 West").model == "claude"


def test_simple_lookup_routes_local():
    assert r.route("show me the census").model == "local"


def test_ops_complexity_routes_cloud():
    assert r.route("analyze the discharge barriers and propose a rebalance").model == "claude"


def test_budget_exhausted_forces_local():
    d = r.route("anything complex here", budget_exhausted=True)
    assert d.model == "local"
    assert d.reason == "budget_exhausted"
