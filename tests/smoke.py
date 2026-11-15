import sys
import urllib.request, urllib.parse, http.cookiejar, re, json
B='http://127.0.0.1:8099'; cj=http.cookiejar.CookieJar(); op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj)); csrf=''
def req(p, data=None, j=None, method=None):
    global csrf
    h={'User-Agent':'smoke'}; body=None
    if j is not None: body=json.dumps(j).encode(); h.update({'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf,'X-Requested-With':'XMLHttpRequest'})
    elif data is not None: body=urllib.parse.urlencode(data).encode()
    try: r=op.open(urllib.request.Request(B+p,data=body,headers=h,method=method)); c,t=r.status,r.read().decode('utf-8','replace')
    except urllib.error.HTTPError as e: c,t=e.code,e.read().decode('utf-8','replace')
    m=re.search(r'name="csrf-token" content="([^"]+)"',t)
    if m: csrf=m.group(1)
    return c,t
req('/login'); req('/login',{'_token':csrf,'email':'admin@example.com','password':'Str0ngPassw0rd!'})
for p in sys.argv[1:]:
    c,t=req(p); bad = c>=500 or 'Fatal' in t or 'Warning:' in t or 'Unexpected server error' in t
    print(('FAIL' if bad else 'ok  '), c, p, (re.sub(r'\s+',' ',t)[:300] if bad else ''))
