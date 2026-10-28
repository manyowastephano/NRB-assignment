# Automated end-to-end checks of the staff pages (needs: pip install requests, app on localhost:8000, fresh sample data, the same STAFF_PASSWORD as in your .env, exported first: export STAFF_PASSWORD=...)
import os, requests, re, sys
PW=os.environ['STAFF_PASSWORD']
B='http://localhost:8000/'
s=requests.Session()
_t=re.search(r'name="csrf" value="([0-9a-f]+)"',s.get(B+'login.php').text).group(1)
s.post(B+'login.php',data=dict(csrf=_t,employee_no='NRB-0101',password=PW))
def tok(url):
    r=s.get(B+'register.php'); m=re.search(r'name="csrf" value="([0-9a-f]+)"',r.text); return m.group(1)
def post(url,data,get=None):
    data=dict(data); data['csrf']=tok(get or url)
    r=s.post(B+url,data=data,allow_redirects=True); return r
def msgs(r):
    out=re.findall(r'<div class="alert alert-(\w+)">(.*?)</div>',r.text,re.S)
    return [(k,re.sub(r'<[^>]+>',' ',v).strip()[:230]) for k,v in out]
base=dict(first_name='Chikondi',middle_name='Alice',surname='Nkhoma',date_of_birth='2000-05-10',gender='F',place_of_birth='Blantyre',
 marital_status='Single',phone_1='+265991000111',phone_2='',category='Citizen',citizenship_basis='Birth',citizenship_cert_no='',acquired_on='',
 village='Soche',ta_id='1',address_type='Permanent',from_date='2010-01-01',postal_box='',physical_description='',
 form_no='NR1',receipt_no='RCP-2026-0900',application_type='New',application_date='2026-10-20',centre_id='1',captured_by='1',
 doc_type='Birth certificate',doc_number='BC-2000-1',issuing_authority='Blantyre Registrar')
print('1 valid citizen   ->',msgs(post('register.php',base))[:1])
print('2 same again      ->',msgs(post('register.php',base))[:1])
bad=dict(base,first_name='',gender='',phone_1='abc',date_of_birth='2099-01-01',receipt_no='x',ta_id='')
print('3 invalid input   ->',msgs(post('register.php',bad))[:1])
d=dict(base,first_name='Dalitso',receipt_no='RCP-2022-0001'); print('4 dup receipt     ->',msgs(post('register.php',d))[:1])
d=dict(base,first_name='Chisomo',middle_name='',surname='Banda',date_of_birth='1998-03-14',receipt_no='RCP-2026-0901'); print('5 dup person      ->',msgs(post('register.php',d))[:1])
d=dict(base,captured_by='3',receipt_no='RCP-2026-0902',first_name='Wrong'); print('6 officer/centre  ->',msgs(post('register.php',d))[:1])
f=dict(base,first_name='Hassan',surname='Ali',category='Foreign resident',passport_no='AB123456',country_of_origin='Tanzania',permit_no='GRP-2026-0999',permit_expiry='2027-12-31',receipt_no='RCP-2026-0903',phone_1='+255712345678')
print('7 foreign valid   ->',msgs(post('register.php',f))[:1])
f2=dict(f,first_name='Hassana',receipt_no='RCP-2026-0904'); print('8 dup permit      ->',msgs(post('register.php',f2))[:1])
x=dict(base,first_name='<script>alert(1)</script>',receipt_no='RCP-2026-0905'); r=post('register.php',x); print('9 xss name        ->',msgs(r)[:1], '<script>alert' in r.text)
r=requests.post(B+'register.php',data=base); print('10 no csrf        ->',msgs(r)[:1])
# find new person ids
r=s.get(B+'applicants.php?q=Nkhoma'); pid=re.search(r'applicant_view.php\?id=(\d+)',r.text).group(1); print('search Nkhoma -> person',pid)
r=s.get(B+'applicants.php?q=RCP-2026-0903'); print('search receipt  ->','Hassan' in r.text)
r=s.get(B+'applicants.php?q=%27%20OR%201%3D1%20--%20'); print('sqli search rows->', r.text.count('applicant_view.php?id='), '(expect 0)')
# update person
e=dict(first_name='Chikondi',middle_name='Alice',surname='Nkhoma-Phiri',maiden_name='Nkhoma',date_of_birth='2000-05-10',gender='F',place_of_birth='Blantyre',marital_status='Married',life_status='Alive',date_of_death='',phone_1='+265991000111',phone_2='+265881000222',citizenship_basis='Birth',citizenship_cert_no='',acquired_on='',section='person',person_id=pid)
print('11 edit person    ->',msgs(post('applicant_edit.php?id='+pid,e,'applicant_edit.php?id='+pid))[:1])
# application: approve without officer, then properly
r=s.get(B+'applicant_edit.php?id='+pid); aid=re.search(r'name="application_id" value="(\d+)"',r.text).group(1)
a=dict(section='application',person_id=pid,application_id=aid,form_no='NR1',application_type='New',status='Approved',approved_by='',approval_date='',rejection_reason='')
print('12 approve no off ->',msgs(post('applicant_edit.php?id='+pid,a,'applicant_edit.php?id='+pid))[:1])
a.update(approved_by='2',approval_date='2026-10-21'); print('13 approve ok     ->',msgs(post('applicant_edit.php?id='+pid,a,'applicant_edit.php?id='+pid))[:1])
a.update(status='Rejected',rejection_reason=''); 
# issue card
c=dict(application_id=aid,card_serial_no='',card_type='',issue_date='2026-10-22',expiry_date='2036-10-22')
print('14 card expiry bad->',msgs(post('cards.php',dict(c,expiry_date='2026-01-01'),'cards.php'))[:1])
print('15 issue card     ->',msgs(post('cards.php',c,'cards.php'))[:1])
print('16 issue twice    ->',msgs(post('cards.php',c,'cards.php'))[:1])
print('17 foreign card>permit ->',end=' ')
r=s.get(B+'applicants.php?q=Hassan'); hp=re.search(r'applicant_view.php\?id=(\d+)',r.text).group(1)
r=s.get(B+'applicant_edit.php?id='+hp); ha=re.search(r'name="application_id" value="(\d+)"',r.text).group(1)
post('applicant_edit.php?id='+hp,dict(section='application',person_id=hp,application_id=ha,form_no='NR1',application_type='New',status='Approved',approved_by='2',approval_date='2026-10-21'),'applicant_edit.php?id='+hp)
print(msgs(post('cards.php',dict(application_id=ha,card_serial_no='',card_type='',issue_date='2026-10-22',expiry_date='2036-10-22'),'cards.php'))[:1])
# card edit
r=s.get(B+'cards.php?q=Nkhoma'); cid=re.search(r'card_edit.php\?id=(\d+)',r.text).group(1)
print('18 card collect   ->',msgs(post('card_edit.php?id='+cid,dict(card_id=cid,card_status='Active',collection_date='2026-10-22'),'card_edit.php?id='+cid))[:1])
print('19 collect before ->',msgs(post('card_edit.php?id='+cid,dict(card_id=cid,card_status='Active',collection_date='2020-01-01'),'card_edit.php?id='+cid))[:1])
# delete
print('20 delete w/ card ->',msgs(post('applicant_delete.php?id='+pid,dict(person_id=pid),'applicant_delete.php?id='+pid))[:1])
r=s.get(B+'applicants.php?q=Hassana'); 
print('21 delete person9 ->',msgs(post('applicant_delete.php?id=9',dict(person_id='9'),'applicant_delete.php?id=9'))[:1])
print('nid assigned:',re.findall(r'MW[A-Z0-9]{7}',s.get(B+'applicant_view.php?id='+pid).text)[:1])
