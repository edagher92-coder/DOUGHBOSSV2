const fs=require("node:fs"),crypto=require("node:crypto");
const root="C:/Codex/Output/doughboss-revesby-readback-20261017/";
const read=n=>JSON.parse(fs.readFileSync(root+n,"utf8").replace(/^\uFEFF/,""));
const hash=v=>crypto.createHash("sha256").update(v).digest("hex");
const text=(v,n)=>typeof v==="string"&&v.length<=n;
const price=v=>typeof v==="number"&&Number.isFinite(v)&&v>=0&&v<=50000&&Math.abs(v*100-Math.round(v*100))<0.000001;
function group(g){if(!g||!text(g.id,64)||!text(g.label,200)||!["radio","check"].includes(g.type)||!Array.isArray(g.choices)||g.choices.length>64)throw Error("GROUP_SCHEMA");return {id:g.id,label:g.label,type:g.type,choices:g.choices.map(c=>{if(!c||!text(c.slug,128)||!text(c.label,200)||!price(c.price)||typeof c.default!=="boolean")throw Error("CHOICE_SCHEMA");return {slug:c.slug,label:c.label,price:c.price,default:c.default};})};}
(async()=>{
const response=await fetch("https://doughboss.com.au/wp-json/doughboss/v1/menu",{redirect:"error",signal:AbortSignal.timeout(15000)});
if(response.status!==200)throw Error("HTTP_STATUS");
const chunks=[];let bytes=0;for await(const chunk of response.body){bytes+=chunk.length;if(bytes>2097152)throw Error("BODY_LIMIT");chunks.push(Buffer.from(chunk));}
const raw=Buffer.concat(chunks),items=JSON.parse(raw.toString("utf8"));if(!Array.isArray(items)||items.length>200)throw Error("ROOT_SCHEMA");
const ids=new Set(),names=new Set(),groups=new Map();
const projected=items.map(i=>{if(!i||!Number.isSafeInteger(i.id)||i.id<=0||!text(i.name,200)||!i.name.trim()||!price(i.price)||!text(i.category,128)||typeof i.available!=="boolean"||!Array.isArray(i.options)||i.options.length>32||!text(i.image,2048)||ids.has(i.id)||names.has(i.name))throw Error("ITEM_SCHEMA");ids.add(i.id);names.add(i.name);if(i.image){const u=new URL(i.image);if(u.protocol!=="https:"||u.origin!=="https://doughboss.com.au"||u.username||u.password)throw Error("IMAGE_ORIGIN");}
const gs=i.options.map(group);for(const g of gs){const key=JSON.stringify(g);if(!groups.has(key))groups.set(key,{group:g,itemIds:[]});groups.get(key).itemIds.push(i.id);}
return {id:i.id,name:i.name,price:i.price,category:i.category,available:i.available,optionGroups:gs.map(g=>({id:g.id,label:g.label,type:g.type,choiceCount:g.choices.length}))};});
const prior=read("website-menu-options-observation.json"),oldGroups=read("website-option-choices-observation.json");
if(!Array.isArray(prior.items)||!Array.isArray(oldGroups.groups))throw Error("BASELINE_SCHEMA");
const oldById=new Map(prior.items.map(i=>[i.id,i]));
const changed=[];for(const i of projected){const old=oldById.get(i.id);if(!old||JSON.stringify(i)!==JSON.stringify(old))changed.push(i.id);}
const missing=prior.items.filter(i=>!ids.has(i.id)).map(i=>i.id);
const currentGroupRows=Array.from(groups.values()).map(x=>({group:x.group,itemIds:x.itemIds.sort((a,b)=>a-b)})).sort((a,b)=>JSON.stringify(a.group).localeCompare(JSON.stringify(b.group)));
const priorGroupRows=oldGroups.groups.map(x=>({group:group(x.group),itemIds:[...x.itemIds].sort((a,b)=>a-b)})).sort((a,b)=>JSON.stringify(a.group).localeCompare(JSON.stringify(b.group)));
const groupMatch=JSON.stringify(currentGroupRows)===JSON.stringify(priorGroupRows);
const report={observedAt:new Date().toISOString(),source:"Anonymous website menu GET",scope:"Current website compared only with explicitly dated 17 October website baselines; not a fresh Square provider readback",bodyBytes:bytes,bodySha256:hash(raw),itemCount:items.length,itemIdentityPriceCategoryAvailabilityAndOptionGroupProjectionMatches:projected.length-changed.length,changedItemIds:changed,missingPriorItemIds:missing,distinctOptionSignatures:currentGroupRows.length,choiceEntriesAcrossSignatures:currentGroupRows.reduce((n,g)=>n+g.group.choices.length,0),optionChoiceLabelsPricesDefaultsAndItemAssignmentsMatchPriorWebsite:groupMatch,itemsWithImageUrl:items.filter(i=>!!i.image).length,itemsWithoutImageUrl:items.filter(i=>!i.image).length,baselineObservedAt:prior.observedAt,baselineOptionsObservedAt:oldGroups.observedAt,baselineFileHashes:{menu:hash(fs.readFileSync(root+"website-menu-options-observation.json")),choices:hash(fs.readFileSync(root+"website-option-choices-observation.json"))},limitations:["Availability is the public menu field, not a verified per-location or Square availability proof.","Image URL presence/origin is not a real photograph, visual correctness, HTTP image validation or browser decoding; URLs may point to branded fallback tiles.","Square item overrides, min/max, object IDs, tax classification, account identity and current provider state remain unverified.","No provider write, payment, deployment or browser/customer-state mutation."]};console.log(JSON.stringify(report,null,2));
})().catch(e=>{console.error(e.message);process.exitCode=1;});
