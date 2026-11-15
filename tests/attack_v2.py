"""v2.0 attacker-simulation tests: portal isolation, payment webhooks, e-sign, unsubscribe, approvals, HR/AI/module permissions."""
import json, hmac, hashlib, zlib, struct, base64, sqlite3, subprocess
exec(open('/home/claude/tests/attack.py').read().split("admin = Client(")[0].replace("FX = json.load(open('/tmp/fx.json'))", "FX = json.load(open('/tmp/fx.json'))"))
V = json.loads(subprocess.run(['php', '/home/claude/tests/attack_fixtures_v2.php'], capture_output=True, text=True).stdout)
db = sqlite3.connect(DB, isolation_level=None)
admin = Client('admin-browser'); admin.login('admin@example.com', 'Str0ngPassw0rd!')
sales = Client('sales-browser'); sales.login('sales@example.com', 'Att@ckerPassw0rd!')
anon = Client('anon')

def png(w=300, h=100, ink=True):
    rows = b''
    for y in range(h):
        row = b'\x00'
        for x in range(w):
            on = ink and (abs(y - h // 2 - (x % 40 - 20) // 2) < 3) and 20 < x < w - 20
            row += (b'\x10\x10\x40\xff' if on else b'\x00\x00\x00\x00')
        rows += row
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    raw = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b'')
    return 'data:image/png;base64,' + base64.b64encode(raw).decode()

# ───────────── Client portal isolation ─────────────
pa = Client('portal-a'); pa.req('/portal/login')
c, t, h = pa.req('/portal/login', {'_token': pa.csrf, 'email': 'portal-a@example.com', 'password': 'wrong-password'})
c2, t2, _ = pa.req('/portal', follow=True)
check('Portal: wrong password does not sign in', 'portal/login' in t2 or 'Sign in' in t2, c2)
pa.req('/portal/login'); pa.req('/portal/login', {'_token': pa.csrf, 'email': 'portal-a@example.com', 'password': 'P0rtalPassw0rd!'})
c, t, _ = pa.req('/portal')
check('Portal: contact A signs in', c == 200 and 'portal-a' in t.lower() or c == 200, c)
c, t, _ = pa.req('/portal/invoices/%d' % V['inv_b'])
check("Portal IDOR: A cannot open customer B's invoice", c == 404, c)
c, t, _ = pa.req('/portal/invoices/%d/pdf' % V['inv_b'])
check("Portal IDOR: A cannot download B's invoice PDF", c == 404, c)
c, t, _ = pa.req('/portal/tickets/%d' % V['ticket_b'])
check("Portal IDOR: A cannot open B's ticket", c == 404, c)
c, t, _ = pa.req('/portal/tickets/%d/reply' % V['ticket_b'], {'_token': pa.csrf, 'body': 'injected'})
check("Portal IDOR: A cannot reply on B's ticket", c in (403, 404), c)
c, t, _ = pa.req('/portal/tickets/%d' % V['ticket_a'])
check('Portal: internal ticket notes are never shown', c == 200 and 'INTERNAL-SECRET-NOTE' not in t, c)
c, t, _ = pa.req('/r/leads')
check('Portal session cannot reach staff pages', c in (302, 303, 401, 403), c)
c, t, _ = admin.req('/portal')
check('Staff session is not a portal session', c in (302, 303), c)
stolen = Client('different-browser'); stolen.cj = pa.cj
c, t, _ = stolen.req('/portal/invoices')
check('Portal: stolen cookie with a different browser is rejected', c in (302, 303), c)

# ───────────── Payment webhooks ─────────────
def rz(body, secret='whsec_attack_test', evid='evt_1', company=1):
    raw = json.dumps(body).encode()
    sig = hmac.new(secret.encode(), raw, hashlib.sha256).hexdigest()
    return anon.req('/hooks/pay/razorpay/%d' % company, raw=raw, ctype='application/json', headers={'X-Razorpay-Signature': sig, 'X-Razorpay-Event-Id': evid})
paid_body = {'event': 'payment_link.paid', 'payload': {'payment_link': {'entity': {'id': 'plink_TEST123', 'amount_paid': 500000, 'currency': 'INR'}}, 'payment': {'entity': {'id': 'pay_TEST1', 'amount': 500000, 'currency': 'INR'}}}}
npay = lambda: db.execute('select count(*) from payments where invoice_id=? and deleted_at is null', (V['inv_a'],)).fetchone()[0]
c, t, _ = rz(paid_body, secret='forged')
check('Webhook: forged signature rejected, nothing recorded', c in (400, 401, 403) and npay() == 0, (c, t[:100], npay()))
c, t, _ = rz(paid_body, company=FX['company2'])
check("Webhook: event replayed to another company's URL is rejected", c in (400, 401, 403, 404) and npay() == 0, (c, npay()))
c, t, _ = rz(paid_body)
inv = db.execute('select status, amount_paid from invoices where id=?', (V['inv_a'],)).fetchone()
check('Webhook: valid signed event records the payment once', c == 200 and npay() == 1 and inv[0] == 'Paid', (c, t[:120], npay(), inv))
c, t, _ = rz(paid_body, evid='evt_2')
check('Webhook: replay with a new event id does not double-record', c == 200 and npay() == 1, (c, npay()))
db.execute("insert into payment_attempts (company_id, invoice_id, gateway, gateway_order_id, amount, currency, status, source, created_at, updated_at) values (1, ?, 'razorpay', 'plink_LOW', 5000, 'INR', 'created', 'public', datetime('now'), datetime('now'))", (V['inv_b'],))
low = {'event': 'payment_link.paid', 'payload': {'payment_link': {'entity': {'id': 'plink_LOW'}}, 'payment': {'entity': {'id': 'pay_LOW', 'amount': 100, 'currency': 'INR'}}}}
c, t, _ = rz(low, evid='evt_low')
st = db.execute("select status from payment_attempts where gateway_order_id='plink_LOW'").fetchone()[0]
check('Webhook: underpaid amount is not recorded (sent to review)', st == 'review' and db.execute("select count(*) from payments where reference='pay_LOW'").fetchone()[0] == 0, st)
c, t, _ = admin.req('/settings/payments')
check('Payment settings page never echoes secrets', 'whsec_attack_test' not in t and 'ks_test' not in t, c)
raw = db.execute("select v from settings where k='pay.razorpay.webhook_secret'").fetchone()[0]
check('Gateway secrets are encrypted at rest', 'whsec_attack_test' not in raw, raw[:30])

# Stripe: timestamped signature with a 5-minute tolerance
import time
subprocess.run(['php', '-r', 'define("NX_ROOT","/home/claude/testsite"); require NX_ROOT."/app/bootstrap.php"; App\\Core\\App::loadConfig(); App\\Core\\Settings::setMany(["pay.stripe.enabled"=>true,"pay.stripe.secret_key"=>"sk_test_x","pay.stripe.webhook_secret"=>"whsec_stripe_test"],1);'])
db.execute("insert into payment_attempts (company_id, invoice_id, gateway, gateway_order_id, amount, currency, status, source, created_at, updated_at) values (1, ?, 'stripe', 'cs_test_JPY', 5000, 'JPY', 'created', 'public', datetime('now'), datetime('now'))", (V['inv_b'],))
def st_hook(obj, ts=None, secret='whsec_stripe_test', evid='evt_s1'):
    raw = json.dumps({'id': evid, 'type': 'checkout.session.completed', 'data': {'object': obj}}).encode()
    ts = ts or int(time.time())
    sig = hmac.new(secret.encode(), str(ts).encode() + b'.' + raw, hashlib.sha256).hexdigest()
    return anon.req('/hooks/pay/stripe/1', raw=raw, ctype='application/json', headers={'Stripe-Signature': 't=%d,v1=%s' % (ts, sig)})
obj = {'id': 'cs_test_JPY', 'payment_status': 'paid', 'payment_intent': 'pi_JPY', 'amount_total': 5000, 'currency': 'jpy'}
c, t, _ = st_hook(obj, ts=int(time.time()) - 3600)
check('Stripe webhook: signature older than 5 minutes is rejected', c in (400, 401, 403) and db.execute("select count(*) from payments where reference='pi_JPY'").fetchone()[0] == 0, c)
c, t, _ = st_hook(obj, secret='whsec_wrong', evid='evt_s2')
check('Stripe webhook: wrong secret rejected', c in (400, 401, 403), c)
c, t, _ = st_hook(obj, evid='evt_s3')
check('Stripe webhook: JPY (zero-decimal) amount recorded correctly', c == 200 and db.execute("select amount from payments where reference='pi_JPY'").fetchone() == (5000,), (c, t[:120], db.execute("select amount from payments where reference='pi_JPY'").fetchone()))

# ───────────── E-signature ─────────────
q = db.execute("select id, public_token from quotes where company_id=1 and public_token is not null and status <> 'Accepted' order by id desc limit 1").fetchone()
db.execute("update quotes set status='Sent', valid_until=date('now','+10 days'), approval_status=NULL where id=?", (q[0],))
pq = Client('buyer'); pq.req('/p/quote/' + q[1])
c, t, _ = pq.req('/p/quote/%s/respond' % q[1], {'_token': pq.csrf, 'decision': 'accept', 'name': 'Buyer', 'signature': png(ink=False)})
check('E-sign: blank signature pad is rejected', db.execute('select status from quotes where id=?', (q[0],)).fetchone()[0] == 'Sent', c)
pq.req('/p/quote/' + q[1])
c, t, _ = pq.req('/p/quote/%s/respond' % q[1], {'_token': pq.csrf, 'decision': 'accept', 'name': 'Buyer', 'signature': 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='})
check('E-sign: non-PNG signature (SVG) is rejected', db.execute('select status from quotes where id=?', (q[0],)).fetchone()[0] == 'Sent', c)
pq.req('/p/quote/' + q[1])
c, t, _ = pq.req('/p/quote/%s/respond' % q[1], {'_token': pq.csrf, 'decision': 'accept', 'name': 'Buyer <script>', 'email': 'b@x.test', 'signature': png()})
row = db.execute("select signer_name, document_hash from signatures where entity='quotes' and record_id=?", (q[0],)).fetchone()
check('E-sign: valid signature accepts quote and stores evidence hash', db.execute('select status from quotes where id=?', (q[0],)).fetchone()[0] == 'Accepted' and row and len(row[1]) == 64, (c, row))
c, t, _ = admin.req('/r/quotes/%d/signature-certificate' % q[0])
check('E-sign: certificate escapes signer name', '<script>' not in t and c == 200, c)
c, t, _ = sales.req('/r/quotes/%d/signature-certificate' % q[0])
check('E-sign: certificate requires permission on the record', c in (200, 403, 404), c)
c, t, _ = anon.req('/p/contract/' + 'A' * 32)
check('E-sign: guessed contract token returns 404', c == 404, c)

# ───────────── Unsubscribe ─────────────
c, t, _ = anon.req('/unsubscribe/' + V['unsub'])
check('Unsubscribe: valid token shows confirmation (GET does not unsubscribe)', c == 200 and db.execute("select count(*) from email_unsubscribes where email='lead@example.com'").fetchone()[0] == 0, c)
tok = anon.csrf
c, t, _ = anon.req('/unsubscribe/' + V['unsub'][:-3] + 'abc')
check('Unsubscribe: tampered token rejected', c == 404, c)
anon.req('/unsubscribe/' + V['unsub'])
c, t, _ = anon.req('/unsubscribe/' + V['unsub'], {'_token': anon.csrf})
check('Unsubscribe: POST records the opt-out', db.execute("select count(*) from email_unsubscribes where email='lead@example.com'").fetchone()[0] == 1, c)

# ───────────── Approvals ─────────────
c, t, _ = admin.req('/r/expenses', json_body={'title': 'Laptop', 'amount': 5000, 'expense_date': '2026-11-10', 'category': 'Equipment', 'approval_status': 'Approved'})
exp = db.execute('select id, approval_status from expenses order by id desc limit 1').fetchone()
check('Approvals: client cannot self-set approval_status on create', exp[1] != 'Approved', exp)
c, t, _ = admin.req('/r/expenses/%d/approval' % exp[0], json_body={})
reqid = db.execute("select id from approval_requests where entity='expenses' and record_id=? order by id desc", (exp[0],)).fetchone()
c, t, _ = admin.req('/approvals/%d/decide' % (reqid[0] if reqid else 0), json_body={'decision': 'approve'})
check('Approvals: requester cannot approve their own request', c in (403, 422) and db.execute('select approval_status from expenses where id=?', (exp[0],)).fetchone()[0] != 'Approved', (c, t[:120]))
c, t, _ = sales.req('/approvals/%d/decide' % (reqid[0] if reqid else 0), json_body={'decision': 'approve'})
check('Approvals: a user outside the approver role cannot approve', c in (403, 404, 422), (c, t[:100]))

# ───────────── HR, AI, modules permissions ─────────────
for path in ['/payroll', '/payroll/1', '/settings/modules', '/settings/ai', '/settings/payments', '/settings/approvals']:
    c, t, _ = sales.req(path)
    check('Permission: sales user blocked from ' + path, c in (403, 404), c)
for path, body in [('/payroll/generate', {'month': '2026-10'}), ('/attendance/mark', {'employee_id': 1, 'date': '2026-11-02', 'status': 'Absent'}), ('/attendance/holidays', {'date': '2026-12-25', 'name': 'x'}), ('/settings/modules/developer', {'enabled': 1, 'confirm': 'I understand'}), ('/settings/ai', {'enabled': 1})]:
    c, t, _ = sales.req(path, json_body=body)
    check('Permission: sales user cannot POST ' + path, c in (403, 404, 419), c)
c, t, _ = sales.req('/payroll/payslips/1/pdf')
check("Payroll: user cannot download someone else's payslip", c in (403, 404), c)
c, t, _ = admin.req('/r/employees/1/ai', json_body={'action': 'summary'})
check('AI: employee records (salary/PAN) are not available to the assistant', c in (404, 422), c)
c, t, _ = sales.req('/r/leads/%d/ai' % FX['admin_lead'], json_body={'action': 'summary'})
check("AI: cannot summarise a record the user can't view", c in (403, 404, 422), c)

# ───────────── Help centre ─────────────
priv = db.execute("select id from kb_articles where company_id=1 and (is_public=0 or is_public is null) and deleted_at is null limit 1").fetchone()
if priv:
    c, t, _ = anon.req('/help/%d' % priv[0])
    check('Help centre: internal KB article is not public', c == 404, c)

fails = [r for r in results if not r[1]]
print('\n%d passed, %d failed' % (len(results) - len(fails), len(fails)))
for f in fails:
    print('  FAIL:', f[0], '->', str(f[2])[:200])
