import urllib.request, urllib.parse, http.cookiejar, re, json, sys
BASE = 'http://127.0.0.1:8099'
cj = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
fails = []
csrf = ''
def req(path, data=None, method=None, headers=None, json_body=None, expect=200, follow=True):
    global csrf
    h = {'User-Agent': 'e2e-test'}
    if headers: h.update(headers)
    body = None
    if json_body is not None:
        body = json.dumps(json_body).encode(); h['Content-Type'] = 'application/json'; h['Accept'] = 'application/json'; h['X-CSRF-Token'] = csrf; h['X-Requested-With'] = 'XMLHttpRequest'
    elif data is not None:
        body = urllib.parse.urlencode(data, doseq=True).encode()
    r = urllib.request.Request(BASE + path, data=body, method=method, headers=h)
    try:
        resp = (op if follow else urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj), NoRedirect)).open(r, timeout=60)
        code, text, hdrs = resp.status, resp.read(), resp.headers
    except urllib.error.HTTPError as e:
        code, text, hdrs = e.code, e.read(), e.headers
    t = text.decode('utf-8', 'replace') if (hdrs.get('Content-Type') or '').startswith(('text', 'application/json')) else text
    m = re.search(r'name="csrf-token" content="([^"]+)"', t) if isinstance(t, str) else None
    if m: csrf = m.group(1)
    ok = code == expect if isinstance(expect, int) else code in expect
    if isinstance(t, str) and ('Fatal error' in t or 'Warning:' in t or 'Unexpected server error' in t): ok = False
    print(('OK  ' if ok else 'FAIL'), code, method or ('POST' if body else 'GET'), path, '' if ok else (t[:300] if isinstance(t, str) else ''))
    if not ok: fails.append(path)
    return code, t, hdrs

# login
req('/login')
code, t, _ = req('/login', {'_token': csrf, 'email': 'admin@example.com', 'password': 'Str0ngPassw0rd!'})
assert 'Dashboard' in t, 'login failed'
pages = ['/', '/calendar', '/reports', '/files', '/notifications', '/profile', '/profile/security', '/settings', '/settings/company', '/settings/users', '/settings/roles', '/settings/roles/2', '/settings/roles/new', '/settings/teams', '/settings/customization', '/settings/fields', '/settings/email', '/settings/storage', '/settings/security', '/settings/audit', '/settings/api', '/settings/workflows', '/settings/workflows/new', '/settings/forms', '/settings/system', '/documents/invoice/new', '/documents/quote/new', '/api/docs', '/api/v1/openapi.json']
for k in ['sales','pipeline','aging','time','tickets','leads','expenses','profitability']: pages.append('/reports/' + k)
ents = ['leads','customers','contacts','deals','products','quotes','invoices','payments','expenses','contracts','projects','milestones','tasks','time_entries','tickets','kb_articles','departments','employees','leave_requests','events']
for e in ents:
    pages += ['/r/' + e, '/r/' + e + '?view=board', '/r/' + e + '/1', '/r/' + e + '/import', '/x/r/' + e + '/form', '/x/r/' + e + '/form?id=1', '/x/r/' + e + '?q=a&f[]=created_at:last_days:30&sort=created_at&dir=desc']
for p in pages:
    req(p)
# exports & pdf
for e in ['leads', 'invoices', 'tasks']:
    c, t, h = req('/r/%s/export?format=csv' % e); c, t, h = req('/r/%s/export?format=xlsx' % e)
c, t, h = req('/documents/invoice/1/pdf'); print('   pdf bytes:', len(t), t[:5] if isinstance(t, bytes) else t[:20])
c, t, h = req('/documents/quote/1/pdf')
# JSON endpoints
req('/x/search?q=acme'); req('/x/notifications'); req('/x/timer'); req('/x/lookup/customers?q=a'); req('/x/lookup/users?q=')
req('/x/calendar?start=2026-11-01&end=2026-12-31')
# create lead via drawer form
c, t, _ = req('/r/leads', json_body={'name': 'Test Lead', 'company_name': 'Test Co', 'email': 'test@lead.com', 'source': 'Website', 'estimated_value': '150000', 'status': 'New'})
lead = json.loads(t); print('   created lead', lead.get('id'), lead.get('warnings'))
# duplicate warning
c, t, _ = req('/r/leads', json_body={'name': 'Dup', 'email': 'test@lead.com'}); print('   dup warnings:', json.loads(t).get('warnings'))
# validation error
req('/r/leads', json_body={'email': 'not-an-email'}, expect=422)
# inline patch + move
req('/x/r/leads/%d' % lead['id'], json_body={'field': 'status', 'value': 'Qualified'}, method='PATCH')
req('/r/leads/%d/move' % lead['id'], json_body={'value': 'Contacted'})
req('/r/leads/%d/activities' % lead['id'], json_body={'type': 'call', 'body': 'Discussed budget', 'subject': 'Intro call'})
# convert lead
c, t, _ = req('/r/leads/%d/convert' % lead['id'], json_body={'create_deal': 1, 'deal_name': 'Test deal', 'deal_amount': '200000'}); print('   convert:', t[:120])
# deal stage move
req('/r/deals/1/move', json_body={'value': '3'})
# bulk update
req('/r/tasks/bulk', json_body={'ids': ['1', '2'], 'action': 'update', 'field': 'priority', 'value': 'High'})
# task done blocked by dependency check
req('/x/r/tasks/3', json_body={'field': 'depends_on', 'value': '5'}, method='PATCH')
req('/x/r/tasks/3', json_body={'field': 'status', 'value': 'Done'}, method='PATCH', expect=422)
# timer
req('/x/timer/start', json_body={'task_id': 4}); req('/x/timer'); req('/x/timer/stop', json_body={})
# invoice create
c, t, _ = req('/documents/invoice', json_body={'customer_id': '1', 'subject': 'API test', 'items': [{'description': 'Dev work', 'quantity': 10, 'unit_price': 1800, 'tax_id': '4'}], 'discount_type': 'percent', 'discount_value': 10})
inv = json.loads(t); print('   invoice:', inv.get('message'))
invid = int(re.search(r'/invoices/(\d+)', inv['redirect']).group(1))
req('/documents/invoice/%d/status' % invid, json_body={'status': 'Sent'})
req('/r/payments', json_body={'invoice_id': str(invid), 'amount': '5000', 'paid_on': '2026-11-15', 'method': 'UPI'})
c, t, _ = req('/r/invoices/%d' % invid); print('   invoice shows Partially Paid:', 'Partially Paid' in t)
req('/documents/quote/1/convert', json_body={})
req('/documents/invoice/from-time/1', json_body={})
req('/documents/invoice/1/email', json_body={'to': 'client@example.com', 'subject': 'Invoice', 'message': 'Hello'})
# ticket reply
req('/r/tickets/1/reply', json_body={'body': 'We are looking into it', 'internal': 0, 'notify': '1'})
# saved view
req('/x/views/leads', json_body={'name': 'Hot leads', 'query': 'f[]=rating:eq:Hot'})
# prefs
req('/x/preferences', json_body={'theme': 'dark'}); req('/x/preferences', json_body={'entity': 'leads', 'columns': ['name', 'status', 'email']})
# public document
import sqlite3
con = sqlite3.connect('/home/claude/testsite/storage/test.sqlite')
tok = con.execute("select public_token from quotes where status='Sent' or status='Invoiced' limit 1").fetchone()
itok = con.execute("select public_token from invoices where status != 'Draft' limit 1").fetchone()
if itok: req('/p/invoice/' + itok[0]); req('/p/invoice/' + itok[0] + '/pdf')
# workflow create
req('/settings/workflows', json_body={'name': 'Hot lead alert', 'entity': 'leads', 'trigger_type': 'created', 'conditions': [{'field': 'rating', 'op': 'eq', 'value': 'Hot'}], 'actions': [{'type': 'create_task', 'title': 'Call {{name}}', 'due_in_days': 1, 'assign_to': 'owner'}, {'type': 'notify', 'to': 'owner', 'title': 'Hot lead {{name}}'}], 'is_active': 1})
c, t, _ = req('/r/leads', json_body={'name': 'Wf Lead', 'rating': 'Hot'})
n = con.execute("select count(*) from tasks where title='Call Wf Lead'").fetchone()[0]; print('   workflow created task:', n == 1)
# custom field
req('/settings/fields', {'_token': csrf, 'entity': 'leads', 'label': 'Tech stack', 'type': 'select', 'options': 'PHP\nReact\nFlutter', 'show_in_list': '1'})
req('/r/leads', json_body={'name': 'CF lead', 'cf_tech_stack': 'PHP'})
req('/x/r/leads?f[]=cf_tech_stack:eq:PHP')
# API
c, t, _ = req('/settings/api/tokens', {'_token': csrf, 'name': 'test', 'scope': 'write', 'user_id': '1'}); m = re.search(r'value="(nx_[^"]+)"', t); token = m.group(1) if m else None; print('   token found:', bool(token))
def api(path, method='GET', body=None, expect=200):
    h = {'Authorization': 'Bearer ' + token, 'Accept': 'application/json'}
    data = None
    if body is not None: data = json.dumps(body).encode(); h['Content-Type'] = 'application/json'
    r = urllib.request.Request(BASE + path, data=data, method=method, headers=h)
    try: resp = urllib.request.urlopen(r); code, text = resp.status, resp.read().decode()
    except urllib.error.HTTPError as e: code, text = e.code, e.read().decode()
    ok = code == expect; print(('OK  ' if ok else 'FAIL'), code, 'API', method, path, '' if ok else text[:200])
    if not ok: fails.append('API ' + path)
    return text
api('/api/v1/me'); api('/api/v1/meta'); api('/api/v1/leads?filter[status]=New&sort=-created_at&per_page=2')
t = api('/api/v1/leads', 'POST', {'name': 'API Lead', 'email': 'api@lead.com', 'source': 'Website'}, 201); aid = json.loads(t)['data']['id']
api('/api/v1/leads/%d' % aid, 'PUT', {'status': 'Contacted'}); api('/api/v1/leads/%d' % aid); api('/api/v1/leads/%d' % aid, 'DELETE', expect=204)
api('/api/v1/invoices/1'); api('/api/v1/search?q=acme'); api('/api/v1/nope', expect=404)
api('/api/v1/leads', 'POST', {'email': 'bad'}, 422)
# CSRF protection
r = urllib.request.Request(BASE + '/r/leads', data=b'name=x', method='POST', headers={'User-Agent': 'e2e-test', 'Accept': 'application/json'})
try: op.open(r); print('FAIL csrf not enforced'); fails.append('csrf')
except urllib.error.HTTPError as e: print(('OK  ' if e.code == 419 else 'FAIL'), e.code, 'CSRF enforced')
# session bound to user agent: a stolen cookie from another browser is rejected
r = urllib.request.Request(BASE + '/x/notifications', headers={'User-Agent': 'attacker', 'Accept': 'application/json'})
try: op.open(r); print('FAIL UA binding'); fails.append('ua')
except urllib.error.HTTPError as e: print(('OK  ' if e.code == 401 else 'FAIL'), e.code, 'session rejected for different user agent')
req('/login'); req('/login', {'_token': csrf, 'email': 'admin@example.com', 'password': 'Str0ngPassw0rd!'})
# access to private paths
for p in ['/app/routes.php', '/config/config.php', '/storage/test.sqlite', '/vendor/autoload_map.php']: req(p, expect=403)
# cron run
req('/settings/system/cron', json_body={})
# backup
req('/settings/system/backup', {'_token': csrf, 'include_code': '1'})
# logout
req('/logout', {'_token': csrf})
c, t, _ = req('/', expect=200); print('   redirected to login after logout:', 'Welcome back' in t)
print('\nFAILURES:', len(fails), fails)
