# Applicant portal + officer approval flow (needs: pip install requests, app on localhost:8000, fresh sample data, the same STAFF_PASSWORD as in your .env, exported first: export STAFF_PASSWORD=...)
import os, requests, re
PW=os.environ['STAFF_PASSWORD']
B='http://localhost:8000/'
def tok(s,url='login.php'):
    return re.search(r'name="csrf" value="([0-9a-f]+)"',s.get(B+url).text).group(1)
def msgs(r):
    return [re.sub(r'\s+',' ',re.sub(r'<[^>]+>',' ',v)).strip()[:170] for k,v in re.findall(r'<div class="alert alert-(\w+)"[^>]*>(.*?)</div>',r.text,re.S)]
anon=requests.Session()
for pth in ['index.php','apply.php','track.php','login.php']:
    print('public',pth,anon.get(B+pth).status_code)
for pth in ['dashboard.php','applications.php','review.php?id=1','applicants.php','register.php','cards.php','applicant_view.php?id=1','applicant_edit.php?id=1','applicant_delete.php?id=1','card_edit.php?id=1']:
    r=anon.get(B+pth,allow_redirects=False); print('anon',pth,r.status_code,r.headers.get('Location'))
# applicant applies
a=requests.Session()
d=dict(csrf=tok(a,'apply.php'),first_name='Tiyamike',middle_name='Rose',surname='Chavula',date_of_birth='2002-08-19',gender='F',phone_1='+265998112233',category='Citizen',citizenship_basis='Birth',village='Mpemba',ta_id='1',centre_id='1',application_type='New',doc_type='Birth certificate',doc_number='BC-2002-55',issuing_authority='Blantyre Registrar',declaration='1')
r=a.post(B+'apply.php',data=dict(d,declaration=''),allow_redirects=True); print('no declaration ->',msgs(r)[:1])
r=a.post(B+'apply.php',data=d,allow_redirects=True); ref=re.search(r'NRB-\d{4}-\d{6}',r.text).group(0); print('apply ok ->',ref,'| confirmation page:', 'What happens next' in r.text)
r=a.post(B+'apply.php',data=dict(d,csrf=tok(a,'apply.php')),allow_redirects=True); print('apply duplicate ->',msgs(r)[:1])
def track(dob):
    return a.post(B+'track.php',data=dict(csrf=tok(a,'track.php'),ref=ref,dob=dob))
print('track wrong dob ->',msgs(track('2000-01-01'))[:1])
t=track('2002-08-19'); print('track ok -> status:',re.findall(r'badge-(\w+)',t.text)[:1], '| shows other person data:', 'Banda' in t.text)
# staff
s=requests.Session()
print('login wrong ->',msgs(s.post(B+'login.php',data=dict(csrf=tok(s),employee_no='NRB-0101',password='bad')))[:1])
print('login portal acct ->',msgs(s.post(B+'login.php',data=dict(csrf=tok(s),employee_no='ONLINE-PORTAL',password=PW)))[:1])
r=s.post(B+'login.php',data=dict(csrf=tok(s),employee_no='NRB-0101',password=PW)); print('login ok ->', 'Welcome, Grace' in r.text)
q=s.get(B+'applications.php?status=Pending&source=online'); print('queue lists new online app:', ref in q.text)
aid=re.search(r'review\.php\?id=(\d+)"[^>]*>Review',q.text).group(1); print('application id',aid)
def act(**kw):
    r=s.post(B+'review.php?id='+aid,data=dict(csrf=tok(s,'review.php?id='+aid),application_id=aid,**kw)); return msgs(r)[:1]
print('approve w/o verification ->',act(action='approve'))
print('reject w/o reason ->',act(action='reject',rejection_reason=''))
print('verify docs ->',act(action='verify',**{'verified[]':'1'}))
print('approve ->',act(action='approve'))
t=track('2002-08-19'); print('track after approval:',re.findall(r'badge-(\w+)',t.text)[:1])
print('approve again ->',act(action='approve'))
# issue card
r=s.post(B+'cards.php#issue',data=dict(csrf=tok(s,'cards.php'),application_id=aid,card_serial_no='',card_type='',issue_date='2026-10-25',expiry_date='2036-10-25')); print('issue card ->',msgs(r)[:1])
t=track('2002-08-19'); print('track after card: ready for collection ->','Your card is ready' in t.text)
# reject path
d2=dict(d,first_name='Rejecta',surname='Example',date_of_birth='2001-01-01',csrf=tok(a,'apply.php'))
r=a.post(B+'apply.php',data=d2,allow_redirects=True); ref2=re.search(r'NRB-\d{4}-\d{6}',r.text).group(0)
q=s.get(B+'applications.php?status=Pending&source=online'); aid2=re.search(r'review\.php\?id=(\d+)"[^>]*>Review',q.text).group(1)
r=s.post(B+'review.php?id='+aid2,data=dict(csrf=tok(s,'review.php?id='+aid2),application_id=aid2,action='reject',rejection_reason='Birth certificate not valid')); print('reject ->',msgs(r)[:1])
t=a.post(B+'track.php',data=dict(csrf=tok(a,'track.php'),ref=ref2,dob='2001-01-01')); print('applicant sees reason:', 'Birth certificate not valid' in t.text)
# dashboard + logout
print('dashboard ok:', s.get(B+'dashboard.php').status_code)
r=s.post(B+'logout.php',data=dict(csrf=tok(s,'dashboard.php')),allow_redirects=True); print('logout ->',msgs(r)[:1], '| dashboard after logout:', s.get(B+'dashboard.php',allow_redirects=False).status_code)
