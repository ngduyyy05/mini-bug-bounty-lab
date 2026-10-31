"""Differential checks on isolated SQLite databases; never contacts external targets."""
import importlib.util
from pathlib import Path
import pytest
from fastapi.testclient import TestClient

ROOT = Path(__file__).resolve().parents[1]

@pytest.fixture(params=['vulnerable', 'fixed'])
def lab(request, tmp_path, monkeypatch):
    mode = request.param
    monkeypatch.chdir(tmp_path)
    monkeypatch.setenv('DB_PATH', str(tmp_path / 'test.db'))
    spec = importlib.util.spec_from_file_location('lab_' + mode, ROOT / f'app-{mode}' / 'main.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    module.templates.env.loader.searchpath = [str(ROOT / f'app-{mode}' / 'templates')]
    with TestClient(module.app, raise_server_exceptions=False) as client:
        password = 'alice123' if mode == 'vulnerable' else 'alice12345'
        response = client.post('/api/login', json={'username': 'alice', 'password': password})
        assert response.status_code == 200
        client.headers['Authorization'] = 'Bearer ' + response.json()['access_token']
        yield mode, client


def test_workbench_renders(lab):
    mode, c = lab
    r = c.get('/')
    assert r.status_code == 200
    assert f'{mode} environment' in r.text
    assert 'Run local check' in r.text
    assert '{{' not in r.text


def test_owner_and_anonymous_controls(lab):
    _, c = lab
    assert c.get('/api/orders/1').json()['user_id'] == 1
    assert c.get('/api/orders/99999').status_code == 404
    assert c.get('/api/orders/1', headers={'Authorization': ''}).status_code == 401


def test_cross_user_order(lab):
    mode, c = lab
    r = c.get('/api/orders/3')
    assert r.status_code == (200 if mode == 'vulnerable' else 404)
    if mode == 'vulnerable':
        assert r.json()['user_id'] == 2


def test_vertical_authorization(lab):
    mode, c = lab
    r = c.get('/api/admin/users')
    assert r.status_code == (200 if mode == 'vulnerable' else 403)


def test_server_side_pricing(lab):
    mode, c = lab
    r = c.post('/api/checkout', json={'product_id': 1, 'quantity': 1, 'amount': 0.01})
    assert r.status_code == 200
    assert r.json()['charged_amount'] == (0.01 if mode == 'vulnerable' else 19.99)


def test_search_injection(lab):
    mode, c = lab
    assert c.get('/api/feedback', params={'search': 'no_such_marker'}).json() == []
    r = c.get('/api/feedback', params={'search': "no_such_marker' OR 1=1 -- "})
    assert r.status_code == 200
    assert bool(r.json()) == (mode == 'vulnerable')


def test_stored_html_escaping(lab):
    mode, c = lab
    marker = '<b data-lab="marker">harmless</b>'
    assert c.post('/api/feedback', json={'message': marker}).status_code == 200
    wall = c.get('/feedback-wall').text
    assert (marker in wall) == (mode == 'vulnerable')
    if mode == 'fixed':
        assert '&lt;b' in wall


def test_untrusted_cors_preflight(lab):
    mode, c = lab
    r = c.options('/api/me', headers={'Origin': 'https://untrusted.invalid', 'Access-Control-Request-Method': 'GET', 'Access-Control-Request-Headers': 'Authorization'})
    assert r.status_code == (200 if mode == 'vulnerable' else 400)
    if mode == 'fixed':
        assert 'access-control-allow-origin' not in r.headers


def test_failed_login_throttle(lab):
    mode, c = lab
    codes = [c.post('/api/login', json={'username': 'nonexistent-test', 'password': 'wrong'}).status_code for _ in range(6)]
    assert codes[:5] == [401] * 5
    assert codes[5] == (401 if mode == 'vulnerable' else 429)


def test_disallowed_upload(lab):
    mode, c = lab
    r = c.post('/api/avatar', files={'file': ('marker.txt', b'harmless lab marker', 'text/plain')})
    assert r.status_code == (200 if mode == 'vulnerable' else 400)
