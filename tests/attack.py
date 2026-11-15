"""Attacker-simulation suite for NextaCRM (runs only against the local test install)."""
import urllib.request, urllib.parse, http.cookiejar, re, json, threading, uuid, sqlite3

BASE = 'http://127.0.0.1:8099'
FX = json.load(open('/tmp/fx.json'))
DB = '/home/claude/testsite/storage/test.sqlite'
results = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, ua='attack-suite'):
        self.cj = http.cookiejar.CookieJar()
        self.csrf = ''
        self.ua = ua

    def req(self, path, data=None, method=None, json_body=None, headers=None, follow=False, raw=None, ctype=None):
        h = {'User-Agent': self.ua}
        if headers:
            h.update(headers)
        body = None
        if json_body is not None:
            body = json.dumps(json_body).encode()
            h.update({'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': self.csrf, 'X-Requested-With': 'XMLHttpRequest'})
        elif raw is not None:
            body = raw
            h['Content-Type'] = ctype
            h['X-CSRF-Token'] = self.csrf
            h['Accept'] = 'application/json'
            h['X-Requested-With'] = 'XMLHttpRequest'
        elif data is not None:
            body = urllib.parse.urlencode(data, doseq=True).encode()
        handlers = [urllib.request.HTTPCookieProcessor(self.cj)] + ([] if follow else [NoRedirect])
        r = urllib.request.Request(BASE + path, data=body, method=method, headers=h)
        try:
            resp = urllib.request.build_opener(*handlers).open(r, timeout=60)
            code, text, hdrs = resp.status, resp.read(), resp.headers
        except urllib.error.HTTPError as e:
            code, text, hdrs = e.code, e.read(), e.headers
        t = text.decode('utf-8', 'replace')
        m = re.search(r'name="csrf-token" content="([^"]+)"', t) or re.search(r'name="_token" value="([^"]+)"', t)
        if m:
            self.csrf = m.group(1)
        return code, t, hdrs

    def login(self, email, password):
        self.req('/login')
        code, t, h = self.req('/login', {'_token': self.csrf, 'email': email, 'password': password})
        self.req('/', follow=True)
        return code


def check(name, cond, detail=''):
    results.append((name, bool(cond), detail))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else '  -> ' + str(detail)[:300]))


def leaked(t, *needles):
    return any(n in t for n in needles)


admin = Client('admin-browser'); admin.login('admin@example.com', 'Str0ngPassw0rd!')
sales = Client('sales-browser'); sales.login('sales@example.com', 'Att@ckerPassw0rd!')
rival = Client('rival-browser'); rival.login('rival@example.com', 'Att@ckerPassw0rd!')
anon = Client('anon')
db = sqlite3.connect(DB)

# ───────────── 1. Cross-tenant IDOR (rival company admin) ─────────────
c, t, _ = rival.req('/r/leads/%d' % FX['admin_lead'])
check('IDOR: rival admin cannot open company-1 lead by ID', c == 404 and 'Admin Private Lead' not in t, c)
c, t, _ = rival.req('/r/leads/%d' % FX['admin_lead'], json_body={'name': 'pwned'})
check('IDOR: rival admin cannot edit company-1 lead', c == 404, c)
c, t, _ = rival.req('/r/leads/%d' % FX['admin_lead'], method='DELETE', json_body={})
check('IDOR: rival admin cannot delete company-1 lead', c == 404, c)
c, t, _ = rival.req('/x/search?q=Admin%20Private')
check('IDOR: global search does not leak other company', 'Admin Private Lead' not in t, t[:200])
c, t, _ = rival.req('/x/lookup/customers?q=')
names_c1 = [r[0] for r in db.execute('select name from customers where company_id=1 limit 5')]
check('IDOR: lookup does not list other company customers', not leaked(t, *names_c1), t[:200])
c, t, _ = rival.req('/documents/invoice/%d/pdf' % FX['invoice_id'])
check('IDOR: rival cannot download company-1 invoice PDF', c in (403, 404), c)
fid = db.execute('select id from files where company_id=1 limit 1').fetchone()
if fid:
    c, t, _ = rival.req('/files/%d/download' % fid[0])
    check('IDOR: rival cannot download company-1 file', c in (403, 404), c)
c, t, _ = rival.req('/r/deals', json_body={'name': 'x', 'customer_id': db.execute('select id from customers where company_id=1 limit 1').fetchone()[0]})
check('IDOR: cannot link record to other company customer', c == 422, (c, t[:200]))
c, t, _ = rival.req('/settings/users/1/reset-2fa', json_body={})
check('IDOR (fixed): rival admin cannot reset 2FA of company-1 admin', c == 404, (c, t[:150]))
c, t, _ = rival.req('/settings/users/1/logout', json_body={})
check('IDOR (fixed): rival admin cannot sign out company-1 users', c == 404, c)
c, t, _ = rival.req('/settings/users/%d' % FX['rival_id'], json_body={'name': 'Rival Admin', 'role_id': 1, 'status': 'active', 'companies': [1, FX['company2']]})
mem = [r[0] for r in db.execute('select company_id from company_user where user_id=?', (FX['rival_id'],))]
check('Privilege escalation (fixed): admin cannot add self to a company they do not belong to', 1 not in mem, mem)
c, t, _ = rival.req('/switch-company', json_body={'company_id': 1})
check('Cannot switch into a company without membership', c == 403, c)

# ───────────── 2. API tenant & scope ─────────────
def api(path, token, method='GET', body=None):
    h = {'Authorization': 'Bearer ' + token, 'Accept': 'application/json', 'User-Agent': 'api-attacker'}
    data = None
    if body is not None:
        data = json.dumps(body).encode(); h['Content-Type'] = 'application/json'
    r = urllib.request.Request(BASE + path, data=data, method=method, headers=h)
    try:
        resp = urllib.request.urlopen(r, timeout=30); return resp.status, resp.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()

c, t = api('/api/v1/leads/%d' % FX['admin_lead'], FX['rival_token'])
check('API IDOR: rival token cannot read company-1 lead', c == 404, c)
c, t = api('/api/v1/leads?per_page=100', FX['rival_token'])
check('API list only returns own tenant', 'Admin Private Lead' not in t and c == 200, c)
c, t = api('/api/v1/leads', FX['admin_read_token'], 'POST', {'name': 'via read token'})
check('API read-only token cannot write', c == 403, c)
c, t = api('/api/v1/leads', FX['rival_token'], 'POST', {'name': 'Mass assign', 'company_id': 1, 'created_by': 1, 'id': 1})
row = db.execute("select company_id, created_by from leads where name='Mass assign' order by id desc limit 1").fetchone()
check('Mass assignment: company_id/created_by from client are ignored', row and row[0] == FX['company2'] and row[1] == FX['rival_id'], row)
c, t = api('/api/v1/leads?sort=name;DROP%20TABLE%20leads--&filter[name][eq]=x%27%20OR%201=1--', FX['rival_token'])
check('SQLi via API sort/filter rejected safely', c in (200, 422) and 'SQLSTATE' not in t, (c, t[:200]))
check('leads table intact after SQLi attempt', db.execute('select count(*) from leads').fetchone()[0] > 0)
c, t = api('/api/v1/employees/%d' % FX['employee_id'], FX['sales_token'])
check('API: sales token cannot read employees (no HR permission)', c in (403, 404), c)
c, t = api('/api/v1/leads', 'nx_' + 'a' * 40)
check('API: forged token rejected', c == 401, c)
codes = [api('/api/v1/me', 'nx_' + uuid.uuid4().hex + 'aaaa')[0] for _ in range(32)]
check('API: repeated bad tokens get rate-limited (429)', 429 in codes, codes[-5:])
c, t = api('/api/v1/leads/abc', FX['rival_token'])
check('API errors do not expose stack traces / paths', 'Stack trace' not in t and '/home/' not in t and '.php' not in t, t[:200])

# ───────────── 3. Vertical privilege escalation (low-privilege Sales user) ─────────────
for p in ['/settings', '/settings/users', '/settings/roles', '/settings/api', '/settings/storage', '/settings/system', '/settings/audit']:
    c, t, _ = sales.req(p)
    check('Sales user blocked from ' + p, c == 403, c)
c, t, _ = sales.req('/settings/users/1', json_body={'role_id': 1, 'status': 'active', 'name': 'x'})
check('Sales user cannot change roles via direct POST', c == 403, c)
c, t, _ = sales.req('/r/leads/%d' % FX['admin_lead'])
check('Sales (team scope, no team) cannot view admin-owned lead', c == 403, c)
c, t, _ = sales.req('/r/leads/%d' % FX['admin_lead'], json_body={'name': 'hijack'})
check('Sales cannot edit admin-owned lead', c == 403, c)
c, t, _ = sales.req('/r/leads/bulk', json_body={'ids': [FX['admin_lead']], 'action': 'delete'})
alive = db.execute('select deleted_at from leads where id=?', (FX['admin_lead'],)).fetchone()[0]
check('Sales bulk-delete of others\' lead is skipped', alive is None, t[:200])
perm = json.loads(db.execute("select permissions from roles where name='Sales'").fetchone()[0])
perm['leads']['assign'] = False
w = sqlite3.connect(DB); w.execute("update roles set permissions=? where name='Sales'", (json.dumps(perm),)); w.commit()
c, t, _ = sales.req('/r/leads', json_body={'name': 'Assign test', 'assigned_to': 1})
row = db.execute("select assigned_to from leads where name='Assign test' order by id desc limit 1").fetchone()
check('User without Assign permission cannot assign records to others', row and row[0] == FX['sales_id'], row)
perm['leads']['assign'] = True; w.execute("update roles set permissions=? where name='Sales'", (json.dumps(perm),)); w.commit()
c, t, _ = sales.req('/r/employees/%d' % FX['employee_id'])
check('Sales cannot open employee (salary) records', c == 403, c)
c, t, _ = sales.req('/r/payments', json_body={'invoice_id': FX['invoice_id'], 'amount': 1, 'paid_on': '2026-11-01'})
check('Sales cannot record payments', c == 403, c)

# ───────────── 4. Injection / XSS ─────────────
xss = '<script>alert(1)</script><img src=x onerror=alert(2)>'
c, t, _ = admin.req('/r/leads', json_body={'name': xss, 'company_name': '"><svg onload=alert(3)>', 'description': 'javascript:alert(4)'})
lid = json.loads(t).get('id')
c, t, _ = admin.req('/r/leads/%d' % lid)
check('Stored XSS escaped on record page', '<script>alert(1)' not in t and 'onerror=alert(2)>' not in t and '<svg onload' not in t, re.findall(r'.{30}alert.{20}', t)[:2])
c, t, _ = admin.req('/r/leads?q=' + urllib.parse.quote(xss))
check('Reflected XSS escaped in list search', '<script>alert(1)' not in t)
c, t, _ = admin.req('/r/leads/%d/activities' % lid, json_body={'type': 'note', 'body': '<b onmouseover=alert(5)>hi</b> https://x.test/"onmouseover="alert(6)'})
c, t, _ = admin.req('/r/leads/%d' % lid)
check('Notes/linkify do not create executable markup', '<b onmouseover' not in t and '"onmouseover="alert(6)' not in t)
c, t, _ = admin.req("/r/leads?q=%27%20OR%201%3D1--&sort=name%20desc,(select%201)&dir=desc;drop")
check('SQLi in list q/sort/dir handled safely', c == 200 and 'SQLSTATE' not in t, c)
c, t, _ = admin.req('/x/r/leads?f[]=' + urllib.parse.quote("name') OR 1=1--:eq:x") + '&f[]=' + urllib.parse.quote('custom.x\'):eq:1'))
check('SQLi in filter field names rejected/ignored', c in (200, 422) and 'SQLSTATE' not in t, (c, t[:200]))
c, t, h = admin.req('/r/leads/%d' % lid)
check('CSP header present and blocks inline script', "script-src 'self'" in (h.get('Content-Security-Policy') or '') and "'unsafe-inline'" not in (h.get('Content-Security-Policy') or '').split('script-src')[1].split(';')[0])
check('Clickjacking protection (X-Frame-Options/frame-ancestors)', h.get('X-Frame-Options') == 'DENY' and "frame-ancestors 'none'" in h.get('Content-Security-Policy', ''))
check('nosniff + Referrer-Policy headers', h.get('X-Content-Type-Options') == 'nosniff' and h.get('Referrer-Policy'))

# ───────────── 5. CSRF / session ─────────────
saved = admin.csrf; admin.csrf = 'forged'
c, t, _ = admin.req('/r/leads', json_body={'name': 'csrf'})
admin.csrf = saved
check('CSRF: forged token rejected (419)', c == 419, c)
c, t, _ = admin.req('/r/leads', data={'name': 'csrf2'})
made = db.execute("select count(*) from leads where name='csrf2'").fetchone()[0]
check('CSRF: form post without token rejected (nothing created)', c in (302, 419) and made == 0, (c, made))
_, _, h = Client().req('/login')
ck = h.get('Set-Cookie') or ''
check('Session cookie HttpOnly + SameSite', 'HttpOnly' in ck and 'SameSite=Lax' in ck, ck)
stolen = Client('different-browser'); stolen.cj = admin.cj
c, t, _ = stolen.req('/')
check('Stolen session cookie from a different browser is rejected', c in (302, 401) , c)
admin.login('admin@example.com', 'Str0ngPassw0rd!')  # binding check cleared admin's session

# ───────────── 6. Open redirect / auth ─────────────
a2 = Client(); a2.req('//evil.example/'); a2.req('/r/leads?x=1')
a2.req('/login'); c, t, h = a2.req('/login', {'_token': a2.csrf, 'email': 'admin@example.com', 'password': 'Str0ngPassw0rd!'})
check('No open redirect after login', not (h.get('Location') or '').startswith(('//', 'http://evil', 'https://evil')), h.get('Location'))
bf = Client('bruteforce')
codes = []
for i in range(12):
    bf.req('/login'); c, t, h = bf.req('/login', {'_token': bf.csrf, 'email': 'sales@example.com', 'password': 'wrong%d' % i}, follow=True)
    codes.append('Too many failed attempts' in t)
check('Brute force: account locks after repeated failures', any(codes), codes)
bf.req('/login'); c, t, h = bf.req('/login', {'_token': bf.csrf, 'email': 'sales@example.com', 'password': 'Att@ckerPassw0rd!'}, follow=True)
check('Locked account cannot sign in even with correct password', 'Too many failed attempts' in t or 'Dashboard' not in t)
c, t, _ = anon.req('/forgot-password'); c1, t1, _ = anon.req('/forgot-password', {'_token': anon.csrf, 'email': 'nobody@nowhere.test'}, follow=True)
anon.req('/forgot-password'); c2, t2, _ = anon.req('/forgot-password', {'_token': anon.csrf, 'email': 'admin@example.com'}, follow=True)
check('Password reset does not reveal whether an account exists', ('If an account exists' in t1) == ('If an account exists' in t2))
c, t, _ = anon.req('/reset-password/' + 'A' * 64, follow=True)
check('Forged reset token rejected', 'invalid or has expired' in t)

# ───────────── 7. File upload & path traversal ─────────────
def multipart(fields, fname, content, mime):
    b = '----nx' + uuid.uuid4().hex
    out = b''
    for k, v in fields.items():
        out += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode()
    out += ('--%s\r\nContent-Disposition: form-data; name="file"; filename="%s"\r\nContent-Type: %s\r\n\r\n' % (b, fname, mime)).encode() + content + ('\r\n--%s--\r\n' % b).encode()
    return out, 'multipart/form-data; boundary=' + b

for fname, content, mime in [('shell.php', b'<?php system($_GET[1]); ?>', 'image/png'), ('shell.php.jpg', b'\xff\xd8\xff<?php echo 1;', 'image/jpeg'),
                             ('x.phtml', b'<?php', 'text/plain'), ('logo.svg', b'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'image/svg+xml'),
                             ('page.html', b'<script>alert(1)</script>', 'text/html'), ('.htaccess', b'SetHandler application/x-httpd-php', 'text/plain'),
                             ('fake.png', b'GIF89a<?php phpinfo();', 'image/png'), ('../../index.php', b'x', 'text/plain')]:
    body, ct = multipart({'_token': admin.csrf}, fname, content, mime)
    c, t, _ = admin.req('/files', raw=body, ctype=ct)
    check('Upload blocked: ' + fname, c in (400, 422, 415), (c, t[:120]))
body, ct = multipart({'_token': admin.csrf}, 'notes.txt', b'hello', 'text/plain')
c, t, _ = admin.req('/files', raw=body, ctype=ct)
check('Legitimate upload still works', c in (200, 201), (c, t[:150]))
row = db.execute("select path from files where name='notes.txt' order by id desc limit 1").fetchone()
check('Stored file name is random (not user-controlled)', row and 'notes' not in row[0], row)
for p in ['/storage/uploads/', '/config/config.php', '/storage/test.sqlite', '/storage/logs/', '/.htaccess', '/vendor/phpmailer/src/PHPMailer.php', '/app/bootstrap.php',
          '/manifest.json', '/docs/SECURITY_PLAN.md', '/tools/release.php', '/.git/config', '/cron.php', '/assets/../config/config.php', '/index.php/../config/config.php']:
    c, t, _ = anon.req(p)
    secret = leaked(t, 'app_key', "'db' =>", 'SQLite format', 'namespace App', 'PHPMailer')
    ok = (c in (400, 401, 403, 404, 503) or (p == '/cron.php' and c in (403, 401))) and not secret
    check('Private path not exposed: ' + p, ok, (c, t[:80]))

# ───────────── 8. Payments & business logic ─────────────
inv = db.execute('select id,total from invoices where id=?', (FX['invoice_id'],)).fetchone()
paid = db.execute('select coalesce(sum(amount),0) from payments where invoice_id=? and deleted_at is null', (inv[0],)).fetchone()[0]
c, t, _ = admin.req('/r/payments', json_body={'invoice_id': inv[0], 'amount': round(inv[1] - paid + 1000, 2), 'paid_on': '2026-11-01', 'method': 'Bank Transfer'})
check('Overpayment rejected (new guard)', c == 422 and 'outstanding' in t, (c, t[:150]))
c, t, _ = admin.req('/r/payments', json_body={'invoice_id': inv[0], 'amount': -500, 'paid_on': '2026-11-01'})
check('Negative payment rejected', c == 422, c)
c, t, _ = admin.req('/r/payments', json_body={'invoice_id': inv[0], 'amount': '1e400', 'paid_on': '2026-11-01'})
check('Absurd/INF payment amount rejected', c == 422, c)
# concurrent payments that together exceed the balance
remaining = round(inv[1] - paid, 2)
half = round(remaining * 0.6, 2)
out = []
def pay():
    cl = Client('admin-browser'); cl.cj = admin.cj; cl.csrf = admin.csrf
    out.append(cl.req('/r/payments', json_body={'invoice_id': inv[0], 'amount': half, 'paid_on': '2026-11-02'})[0])
ths = [threading.Thread(target=pay) for _ in range(3)]
[x.start() for x in ths]; [x.join() for x in ths]
total_paid = db.execute('select coalesce(sum(amount),0) from payments where invoice_id=? and deleted_at is null', (inv[0],)).fetchone()[0]
check('Race: concurrent payments never exceed invoice total', total_paid <= inv[1] + 0.01, (out, total_paid, inv[1]))
st = db.execute('select status, balance from invoices where id=?', (inv[0],)).fetchone()
cust = db.execute('select customer_id from invoices where id=?', (inv[0],)).fetchone()[0]
c, t, _ = admin.req('/documents/invoice', json_body={'customer_id': cust, 'issue_date': '2026-11-01', 'due_date': '2026-11-15', 'currency': 'INR', 'status': 'Paid', 'total': 1, 'items': [{'description': 'Server', 'quantity': 2, 'unit_price': 5000}]})
d = json.loads(t) if t.startswith('{') else {}
newinv = db.execute('select total,status from invoices order by id desc limit 1').fetchone()
check('Client-supplied total/status ignored; total computed server-side', newinv[0] == 10000 and newinv[1] == 'Draft', newinv)
c, t, _ = admin.req('/documents/invoice', json_body={'customer_id': cust, 'issue_date': '2026-11-01', 'due_date': '2026-11-15', 'items': [{'description': 'neg', 'quantity': -5, 'unit_price': 1000}]})
check('Negative quantity rejected', c == 422, (c, t[:120]))
c, t, _ = admin.req('/documents/invoice', json_body={'customer_id': cust, 'issue_date': '2026-11-01', 'due_date': '2026-11-15', 'items': [{'description': 'credit only', 'quantity': 1, 'unit_price': -1000}]})
check('Negative invoice total rejected', c == 422, (c, t[:120]))
c, t, _ = admin.req('/documents/invoice', json_body={'customer_id': cust, 'issue_date': '2026-11-01', 'due_date': '2026-11-15', 'items': [{'description': 'disc', 'quantity': 1, 'unit_price': 1000, 'discount_percent': 900}], 'discount_type': 'percent', 'discount_value': 500})
last = db.execute('select total from invoices order by id desc limit 1').fetchone()[0]
check('Discounts are clamped (no negative totals via 900% discount)', last >= 0, last)
paid_inv = db.execute("select id from invoices where status='Paid' and company_id=1 limit 1").fetchone()
if paid_inv:
    c, t, _ = admin.req('/documents/invoice/%d' % paid_inv[0], json_body={'customer_id': cust, 'items': [{'description': 'changed', 'quantity': 1, 'unit_price': 1}]})
    check('Paid invoices cannot be silently edited', c == 422, (c, t[:120]))

# public quote acceptance
pq = Client('customer')
c, t, _ = pq.req('/p/quote/' + FX['quote_valid'])
import zlib, struct, base64
def _png():
    w, h = 200, 60
    rows = b''.join(b'\x00' + b''.join((b'\x00\x00\x00\xff' if abs(y - 30) < 3 and 20 < x < 180 else b'\x00\x00\x00\x00') for x in range(w)) for y in range(h))
    ch = lambda t, d: struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return 'data:image/png;base64,' + base64.b64encode(b'\x89PNG\r\n\x1a\n' + ch(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0)) + ch(b'IDAT', zlib.compress(rows)) + ch(b'IEND', b'')).decode()
c1, t1, _ = pq.req('/p/quote/%s/respond' % FX['quote_valid'], {'_token': pq.csrf, 'decision': 'accept', 'name': 'Buyer', 'signature': _png()})
pq.req('/p/quote/' + FX['quote_valid'])
c2, t2, _ = pq.req('/p/quote/%s/respond' % FX['quote_valid'], {'_token': pq.csrf, 'decision': 'decline'})
st = db.execute('select status from quotes where public_token=?', (FX['quote_valid'],)).fetchone()[0]
check('Quote: accept works once, replay/second response rejected', c1 in (302, 303) and c2 == 422 and st == 'Accepted', (c1, c2, st))
pq.req('/p/quote/' + FX['quote_expired'])
c, t, _ = pq.req('/p/quote/%s/respond' % FX['quote_expired'], {'_token': pq.csrf, 'decision': 'accept', 'name': 'Late', 'signature': _png()})
check('Expired quote cannot be accepted (new guard)', c == 422, c)
c, t, _ = anon.req('/p/quote/' + 'x' * 32)
check('Guessed public document token returns 404', c == 404, c)
c, t, _ = anon.req('/p/invoice/' + FX['quote_valid'])
check('Quote token cannot open an invoice', c == 404, c)

# ───────────── 9. SSRF ─────────────
for url in ['http://127.0.0.1:8099/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'file:///etc/passwd', 'gopher://127.0.0.1:25/']:
    c, t, _ = admin.req('/settings/webhooks', json_body={'name': 's', 'url': url, 'events': ['record.created']})
    check('SSRF: webhook to %s refused' % url, c == 422, (c, t[:100]))
c, t, _ = admin.req('/settings/webhooks', json_body={'name': 'dns-rebind', 'url': 'http://localhost:8099/', 'events': ['record.created']})
wid = db.execute("select id from webhooks where name='dns-rebind' order by id desc limit 1").fetchone()
if wid:
    c, t, _ = admin.req('/settings/webhooks/%d/test' % wid[0], json_body={})
    check('SSRF: hostname resolving to localhost blocked at delivery', 'blocked' in t.lower() or 'private' in t.lower() or '"ok":false' in t, t[:200])

# ───────────── 10. Abuse / DoS guards ─────────────
c, t, _ = admin.req('/settings/workflows', json_body={'name': 'Loop', 'entity': 'leads', 'trigger_type': 'updated', 'conditions': [], 'actions': [{'type': 'update_field', 'field': 'description', 'value': 'x{{now}}'}], 'is_active': 1})
c, t, _ = admin.req('/x/r/leads/%d' % lid, json_body={'field': 'status', 'value': 'Contacted'}, method='PATCH')
check('Workflow self-trigger loop is contained (no crash)', c == 200, (c, t[:150]))
db2 = sqlite3.connect(DB)
db2.execute("update workflows set is_active=0 where name='Loop'"); db2.commit()
c, t, _ = admin.req('/x/r/leads?per_page=100000')
check('Page size is capped server-side', c == 200 and len(json.loads(t).get('rows', json.loads(t).get('items', []))) <= 500 if t.startswith('{') else c == 200)

# ───────────── 11. Scheduler entry points ─────────────
import subprocess
out = subprocess.run(['php', '/home/claude/testsite/cron.php'], capture_output=True, text=True, timeout=120).stdout
check('cron.php (CLI) runs all jobs', '"ok": true' in out and 'purge_deleted' in out, out[:200])
key = subprocess.run(['php', '-r', 'define("NX_ROOT","/home/claude/testsite"); require NX_ROOT."/app/bootstrap.php"; App\\Core\\App::loadConfig(); echo App\\Core\\Settings::get("cron.key","");'], capture_output=True, text=True).stdout.strip()
c, t, _ = anon.req('/cron.php?key=' + key)
check('cron.php (secret URL) runs with the right key', c == 200 and '"ok": true' in t, (c, t[:120]))
c, t, _ = anon.req('/cron.php?key=guess')
check('cron.php refuses a wrong key', c == 403, c)

fails = [r for r in results if not r[1]]
print('\n%d passed, %d failed' % (len(results) - len(fails), len(fails)))
for f in fails:
    print('  FAIL:', f[0], '->', str(f[2])[:200])
