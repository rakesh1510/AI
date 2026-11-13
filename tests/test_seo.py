"""Offline HTTP regression suite. Uses generated fixtures; never reads db.php or production data.
Run: PHP_BIN=php python3 -m unittest discover -s tests -v
"""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time, unittest
import urllib.request, urllib.error, urllib.robotparser
import xml.etree.ElementTree as ET
from html.parser import HTMLParser

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
FILES = ['seo.php','sitemap-data.php','helpers.php','layout_top.php','layout_bottom.php','org-schema.php',
         'trust-badges.php','homepage-trust-section.php','index.php','tool.php','category.php','categories.php',
         'compare.php','ai-use-case.php','post.php','blog-seo.php','404.php','sitemap.php','sitemap.xml','sitemap-static.xml','robots.txt']

class Head(HTMLParser):
    def __init__(self):
        super().__init__(); self.in_head=False; self.tags=[]; self.jsons=[]; self.capture=False; self.body=''
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='head': self.in_head=True
        self.tags.append((tag,attrs,self.in_head))
        if tag=='script' and attrs.get('type')=='application/ld+json': self.capture=True; self.body=''
    def handle_data(self,data):
        if self.capture: self.body+=data
    def handle_endtag(self,tag):
        if tag=='head': self.in_head=False
        if tag=='script' and self.capture:
            self.jsons.append(json.loads(self.body)); self.capture=False
    def values(self,tag,key,value,out):
        return [a.get(out) for t,a,h in self.tags if t==tag and a.get(key)==value]

class SEO(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory(prefix='aihub-seo-'); cls.path=pathlib.Path(cls.tmp.name)
        for f in FILES: shutil.copyfile(ROOT/f,cls.path/f)
        (cls.path/'data').mkdir()
        base={'category':'Writing & Research','short':'A recorded description.','description':'A recorded description.',
              'features':[], 'pros':[], 'cons':[], 'use_cases':[], 'tags':[], 'pricing':'', 'website':'https://example.com',
              'seo_title':'', 'seo_description':'', 'affiliate':''}
        fixtures={'tools.json':{'tools':[dict(base,id='alpha',name='Alpha </script><script>alert(1)</script>'),
                                         dict(base,id='beta',name='Beta'),dict(base,id='draft',name='Draft',status='draft')]},
                  'site.json':{},'posts.json':[{'slug':'guide','title':'Test guide','excerpt':'Test excerpt','content':'<p>A test guide.</p>','date':'2026-01-01'}],
                  'use_cases.json':{'writing':{'label':'Writing','description':'Test case'}}}
        for f,data in fixtures.items(): (cls.path/'data'/f).write_text(json.dumps(data))
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); cls.port=sock.getsockname()[1]
        cls.log=open(cls.path/'server.log','w+')
        cls.server=subprocess.Popen([PHP,'-n','-d','display_errors=1','-S',f'127.0.0.1:{cls.port}','-t',str(cls.path)],stdout=cls.log,stderr=cls.log)
        for i in range(50):
            try: urllib.request.urlopen(f'http://127.0.0.1:{cls.port}/robots.txt',timeout=1); break
            except OSError: time.sleep(.05)
        else: raise RuntimeError('PHP server did not start')
    @classmethod
    def tearDownClass(cls):
        cls.server.terminate(); cls.server.wait(timeout=5); cls.log.close(); cls.tmp.cleanup()
    def get(self,path,ua='SEORegression/1.0'):
        req=urllib.request.Request(f'http://127.0.0.1:{self.port}{path}',headers={'User-Agent':ua,'Host':'untrusted.example'})
        try: r=urllib.request.urlopen(req)
        except urllib.error.HTTPError as e: r=e
        body=r.read().decode(); self.assertNotIn('Fatal error',body); self.assertNotIn('Warning:',body)
        return r.status,body
    def parsed(self,path):
        status,body=self.get(path); p=Head(); p.feed(body); return status,body,p
    def test_metadata_and_json(self):
        for path in ['/', '/tool.php?id=alpha&utm_source=test','/category.php?name=Writing%20%26%20Research','/compare.php','/post.php?slug=guide']:
            with self.subTest(path=path):
                status,body,p=self.parsed(path); self.assertEqual(status,200)
                canon=p.values('link','rel','canonical','href'); self.assertEqual(len(canon),1); self.assertTrue(canon[0].startswith('https://aihubpedia.com/')); self.assertNotIn('utm_',canon[0])
                self.assertEqual(p.values('meta','property','og:url','content'),canon)
                self.assertEqual(len(p.values('meta','name','robots','content')),1)
                self.assertTrue(all(h for t,a,h in p.tags if t=='meta' or (t=='link' and a.get('rel')=='canonical')))
                self.assertTrue(p.jsons)
                self.assertNotIn('<script>alert(1)</script>',body)
                self.assertFalse(p.values('link','rel','alternate','hreflang'))
    def test_tool_metadata_fallback_and_no_fabrication(self):
        _,body,p=self.parsed('/tool.php?id=alpha')
        self.assertIn('Features, Pricing',body); self.assertIn('Pricing verification date is not recorded',body)
        self.assertNotIn('Average demo rating',body); self.assertNotIn('Free tier available',body)
        self.assertFalse(any('aggregateRating' in s or 'offers' in s for s in p.jsons))
    def test_search_noindex_in_head(self):
        _,_,p=self.parsed('/?q=alpha'); self.assertEqual(p.values('meta','name','robots','content'),['noindex,follow'])
    def test_missing_and_unpublished_404(self):
        for path in ['/category.php','/category.php?name=missing','/?cat=missing','/tool.php?id=missing','/tool.php?id=draft','/compare.php?a=missing','/post.php?slug=missing','/tool.php?id[]=x','/ai-use-case.php?slug=missing']:
            with self.subTest(path=path):
                status,_,p=self.parsed(path); self.assertEqual(status,404); self.assertEqual(p.values('meta','name','robots','content'),['noindex,follow'])
    def test_category_alias_and_tracking(self):
        _,_,p=self.parsed('/?cat=Writing%20%26%20Research&utm_campaign=x')
        self.assertEqual(p.values('link','rel','canonical','href'),['https://aihubpedia.com/category.php?name=Writing%20%26%20Research'])
    def test_comparison_order_and_read_only(self):
        before={f.name:f.read_bytes() for f in (self.path/'data').iterdir()}
        for query in ['a=alpha&b=beta','b=alpha&a=beta']:
            status,body,p=self.parsed('/compare.php?'+query)
            self.assertEqual(status,200); self.assertIn('Not recorded',body)
            self.assertEqual(p.values('link','rel','canonical','href'),['https://aihubpedia.com/compare.php?a=alpha&b=beta'])
            self.assertEqual(p.values('meta','name','robots','content'),['noindex,follow'])
        self.assertEqual(before,{f.name:f.read_bytes() for f in (self.path/'data').iterdir()})
    def test_sitemap_xml_and_public_urls(self):
        for path in ['/sitemap.xml','/sitemap-static.xml','/sitemap.php']:
            status,body=self.get(path); self.assertEqual(status,200); root=ET.fromstring(body)
            urls=[e.text for e in root.findall('.//{*}loc')]; self.assertEqual(len(urls),len(set(urls)))
            self.assertTrue(all(u.startswith('https://aihubpedia.com/') for u in urls)); self.assertNotIn('<lastmod>',body)
            if path=='/sitemap.php':
                self.assertIn('https://aihubpedia.com/tool.php?id=alpha',urls)
                self.assertIn('https://aihubpedia.com/category.php?name=Writing%20%26%20Research',urls)
                self.assertIn('https://aihubpedia.com/post.php?slug=guide',urls)
                self.assertFalse(any('draft' in u or '/tools/' in u or 'compare.php?' in u for u in urls))
    def test_crawler_policy_and_equal_content(self):
        bots=['Googlebot','bingbot','DuckDuckBot','OAI-SearchBot','PerplexityBot','Claude-SearchBot','GPTBot','ClaudeBot','Google-Extended']
        policy=urllib.robotparser.RobotFileParser(); policy.parse((ROOT/'robots.txt').read_text().splitlines())
        expected=self.get('/tool.php?id=alpha')[1]
        for bot in bots:
            with self.subTest(bot=bot):
                self.assertTrue(policy.can_fetch(bot,'https://aihubpedia.com/tool.php?id=alpha'))
                self.assertFalse(policy.can_fetch(bot,'https://aihubpedia.com/data/private.json'))
                self.assertEqual(self.get('/tool.php?id=alpha',bot)[1],expected)
        self.assertEqual(policy.site_maps(),['https://aihubpedia.com/sitemap.xml'])
    def test_helpers_in_isolation(self):
        code="require 'seo.php'; echo seo_json_ld(['canonical'=>seo_canonical('http://evil.example/tool.php?id=alpha&utm_source=x'), 'translations'=>seo_language_links(['de'=>'https://aihubpedia.com/de/tool.php?id=alpha','fr'=>'https://evil.example/','bad tag'=>'https://aihubpedia.com/x']), 'unsafe'=>seo_http_url('javascript:alert(1)')]);"
        result=subprocess.run([PHP,'-n','-r',code],cwd=self.path,capture_output=True,text=True,check=True)
        out=json.loads(result.stdout); self.assertEqual(out['canonical'],'https://aihubpedia.com/tool.php?id=alpha'); self.assertEqual(list(out['translations']),['de']); self.assertEqual(out['unsafe'],'')

if __name__=='__main__': unittest.main()
