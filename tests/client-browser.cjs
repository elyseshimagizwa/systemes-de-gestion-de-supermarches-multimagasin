// Run with Node 24; local Chrome and MySQL only. Creates and removes dedicated fixtures.
const {spawn, execFileSync} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const tag = 'testclient' + Date.now();
const email = tag + '@example.invalid';
const password = 'Test-' + tag;
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'saas-browser-'));
const report = {steps: [], errors: []};
const sql = query => execFileSync('C:/xamp/mysql/bin/mysql.exe', ['-u','root','--batch','--skip-column-names','--default-character-set=utf8mb4','e_servicesburundi','-e',query], {encoding:'utf8'}).trim();
let chrome, ws, productId, storeId;
const sleep = ms => new Promise(r => setTimeout(r,ms));
const timer = setTimeout(() => { console.error('Browser test timeout'); process.exit(1); }, 120000);
(async () => {
 storeId = Number(sql("SELECT id FROM magasins WHERE statut='actif' LIMIT 1"));
 if (!storeId) throw Error('No active store');
 productId = Number(sql(`INSERT INTO produits (magasin_id,nom,codebarre,prix_vente,quantite) VALUES (${storeId},'${tag}','${tag}',12.50,5); SELECT LAST_INSERT_ID();`));
 sql(`INSERT INTO lots_produits(produit_id,magasin_id,numero_lot,quantite_initiale,quantite_restante) VALUES (${productId},${storeId},'${tag}',5,5)`);
 chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new','--disable-gpu','--no-first-run','--remote-debugging-port=9338','--user-data-dir='+profile,'about:blank'], {stdio:'ignore'});
 let pages;
 for(let i=0;i<50;i++){try{pages=await (await fetch('http://127.0.0.1:9338/json')).json();break;}catch{await sleep(200);}}
 if(!pages) throw Error('Chrome unavailable');
 ws = new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);
 await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});
 let id=0;const pending=new Map();
 ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(JSON.stringify(m.error))):p.resolve(m.result);}
 if(m.method==='Runtime.exceptionThrown')report.errors.push(m.params.exceptionDetails);
 if(m.method==='Network.responseReceived'&&m.params.response.status>=400)report.errors.push({url:m.params.response.url,status:m.params.response.status});};
 const call=(method,params={})=>new Promise((resolve,reject)=>{const key=++id;pending.set(key,{resolve,reject});ws.send(JSON.stringify({id:key,method,params}));});
 const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;};
 const check=async(name,expr)=>{const ok=await ev(expr);report.steps.push({name,ok});if(!ok)throw Error(name);};
 const nav=async page=>{await call('Page.navigate',{url:'http://localhost/saas/'+page});await sleep(1800);};
 await call('Runtime.enable');await call('Network.enable');await call('Page.enable');
 await nav('index.php?q='+tag);
 await ev(`document.querySelector('button[onclick="addToCart(${productId})"]').click()`);
 await check('Panier visible et total correct',`getComputedStyle(document.getElementById('cart-panel')).display!=='none' && document.getElementById('cart-total').textContent==='12.50'`);
 await nav('inscription_client.php');
 await ev(`for(const [k,v] of Object.entries(${JSON.stringify({nom:tag,email,password,confirmation:password})}))document.querySelector('[name="'+k+'"]').value=v; document.querySelector('form').requestSubmit()`);
 await sleep(2200);
 await check('Inscription retourne index connecte sans liens login/inscription',`location.pathname.endsWith('/index.php') && document.body.innerText.includes('Bonjour, ${tag}') && !document.querySelector('nav a[href="login.php"]') && !document.querySelector('nav a[href="inscription_client.php"]')`);
 await ev('toggleCart(true)');
 await check('Panier conserve apres inscription',`document.querySelectorAll('#cart-lines .cart-line').length===1`);
 await ev(`document.querySelector('[name="magasin_id"]').value='${storeId}';document.querySelector('[name="mode_paiement"]').checked=true`);
 const originalOrder = await ev(`Object.fromEntries(new FormData(document.querySelector('#cart-panel form')))`);
 await ev(`document.querySelector('#cart-panel form').requestSubmit()`);
 await sleep(3500);
 await check('Confirmation commande apres redirection',`location.pathname.endsWith('/mes_commandes.php') && document.body.innerText.includes('enregistrée.') && document.querySelector('[data-order-status]').textContent==='En attente' && document.body.innerText.includes('Code de retrait') && !localStorage.getItem('client_cart')`);
 await check('Notification initiale visible',`Number(document.getElementById('client-unread').textContent)===1 && document.getElementById('client-notifications').innerText.includes('Commande reçue')`);
 await ev(`document.querySelector('#client-notifications form').requestSubmit()`);
 await sleep(2000);
 await check('Notification marquee lue',`document.getElementById('client-unread').textContent==='0'`);
 const uid=Number(sql(`SELECT id FROM utilisateurs WHERE email='${email}'`));
 const orderId=Number(sql(`SELECT id FROM commandes_clients WHERE utilisateur_id=${uid} LIMIT 1`));
 const operatorId=Number(sql("SELECT id FROM utilisateurs WHERE role='admin' LIMIT 1"));
 if (!operatorId) throw Error('No administrator fixture operator available');
 execFileSync('C:/xamp/php/php.exe', ['-r', `$pdo=new PDO('mysql:host=localhost;dbname=e_servicesburundi;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); require 'C:/xamp/htdocs/saas/includes/client-orders.php'; updateClientOrderStatus($pdo,${orderId},'Confirmée',${operatorId}); updateClientOrderStatus($pdo,${orderId},'En préparation',${operatorId});`], {encoding:'utf8'});
 await sleep(22000);
 await check('Statut et historique actualises automatiquement',`document.querySelector('[data-order-status]').textContent==='En préparation' && document.querySelector('#client-orders ol').innerText.includes('Confirmée') && document.querySelector('#client-orders ol').innerText.includes('En préparation')`);
 await check('Notifications de progression recues',`document.getElementById('client-unread').textContent==='2' && document.getElementById('client-notifications').innerText.includes('En préparation')`);
 const count=()=>Number(sql(`SELECT COUNT(*) FROM commandes_clients WHERE utilisateur_id=${uid}`));
 if(count()!==1 || Number(sql(`SELECT quantite FROM produits WHERE id=${productId}`))!==4)throw Error('Stock/order inconsistent');
 report.steps.push({name:'Une commande, stock et lot debites une fois',ok:Number(sql(`SELECT quantite_restante FROM lots_produits WHERE produit_id=${productId}`))===4});
 // Re-submit the original token through the same browser session.

 const duplicate = await ev(`fetch('index.php',{method:'POST',body:new URLSearchParams(${JSON.stringify(originalOrder)})}).then(r=>r.text())`);
 if(!duplicate.includes('déjà été utilisée') || count()!==1)throw Error('Duplicate order protection failed');
 report.steps.push({name:'Double validation refusee sans nouveau debit',ok:true});
 await nav('logout.php');await nav('login.php');
 await check('Login sans footer',`!document.querySelector('footer') && !document.body.innerText.includes(String.fromCharCode(169))`);
 await ev(`document.querySelector('[name="email"]').value=${JSON.stringify(email)};document.querySelector('[name="password"]').value=${JSON.stringify(password)};document.querySelector('form').requestSubmit()`);
 await sleep(2500);
 await check('Connexion client retourne index connecte',`location.pathname.endsWith('/index.php') && document.body.innerText.includes('Bonjour, ${tag}')`);
 await nav('mes_commandes.php');
 await check('Commande visible dans espace client',`document.body.innerText.includes('${tag}') && document.body.innerText.includes('WEB-')`);
 await nav('logout.php');
 report.status='PASS';
})().catch(e=>{report.status='FAIL';report.failure=e.message;process.exitCode=1;}).finally(()=>{
 clearTimeout(timer);
 if(ws)ws.close();if(chrome)chrome.kill();
 try {
 const uid=Number(sql(`SELECT id FROM utilisateurs WHERE email='${email}'`));
 if(uid){
 sql(`DELETE h FROM historique_commandes_clients h JOIN commandes_clients c ON c.id=h.commande_id WHERE c.utilisateur_id=${uid}; DELETE FROM notifications_clients WHERE utilisateur_id=${uid}; DELETE l FROM lignes_commandes_clients l JOIN commandes_clients c ON c.id=l.commande_id WHERE c.utilisateur_id=${uid}; DELETE FROM commandes_clients WHERE utilisateur_id=${uid}; DELETE FROM connexions_utilisateurs WHERE utilisateur_id=${uid};`);
 }
 if(productId)sql(`DELETE FROM stock_mouvements WHERE produit_id=${productId}; DELETE FROM lots_produits WHERE produit_id=${productId}; DELETE FROM produits WHERE id=${productId}`);
 if(uid)sql(`DELETE FROM utilisateurs WHERE id=${uid}`);
 report.cleanup='Dedicated customer, orders, product and lots removed; security audit retained';
 }catch(e){report.cleanup=e.message;report.status='FAIL';process.exitCode=1;}
 fs.writeFileSync(path.join(__dirname,'client-browser-result.json'),JSON.stringify(report,null,2));
 console.log(JSON.stringify(report,null,2));
});
