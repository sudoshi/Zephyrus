from app.sanitizer import sanitize, scan


def test_ssn_redacted():
    r = sanitize("patient ssn 123-45-6789 needs a bed")
    assert r.redaction_count == 1
    assert "123-45-6789" not in r.text
    assert "[REDACTED:ssn]" in r.text


def test_mrn_and_dob():
    assert sanitize("MRN: 00123456").redaction_count == 1
    assert sanitize("DOB 01/02/1980").redaction_count == 1


def test_clean_ops_text_has_no_redactions():
    assert scan("net bed need is 12; 3 ED boarders; census 41 min old") == 0


def test_scan_across_fragments():
    assert scan("hello", "call 215-555-1234", "{}") == 1
