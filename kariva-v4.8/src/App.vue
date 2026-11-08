<script setup lang="ts">
import {computed, ref, watch, onMounted, onUnmounted, nextTick} from 'vue';
import {session,errorText} from './api/session';import type {MessageKey} from './i18n/fa';import {auth as authApi,catalog,actors as actorsApi,services as servicesApi,media as mediaApi,team as teamApi,portfolio as portfolioApi} from './api/endpoints';import {ApiError} from './api/client';import {trust} from './api/endpoints';import {ui,tr,money,rememberLang} from './state/ui';import {live,startLive} from './state/live';import OrdersPanel from './components/OrdersPanel.vue';import NeedsBoard from './components/NeedsBoard.vue';import ChatPanel from './components/ChatPanel.vue';import NotificationsPanel from './components/NotificationsPanel.vue';import AdminPanel from './components/AdminPanel.vue';import VerificationPanel from './components/VerificationPanel.vue';import ReviewsModal from './components/ReviewsModal.vue';import RequestOrderModal from './components/RequestOrderModal.vue';import type {Notice as ApiNotice,Need} from './api/types';import {needs as needsApi} from './api/endpoints';import type {Category,Province,Actor as ApiActor,Service as ApiService,Member,Invitation,Role} from './api/types';
import {Home,Compass,BriefcaseBusiness,UserRound,Plus,Search,Bell,Moon,Sun,MapPin,Heart,MessageCircle,Share2,Bookmark,Menu,X,Phone,ArrowUpRight,ImagePlus,CheckCircle2,Users,Send,Building2,Settings2,Globe2,Camera,Trash2,Edit3,ChevronLeft,LogOut,Star,Flag,Paperclip,CheckCheck,ShieldBan} from 'lucide-vue-next';
type Lang='fa'|'ps'|'en'; type Tab='home'|'explore'|'jobs'|'profile'|'pages'|'portfolioManager'|'settings'|'team'|'messages'|'requests'|'notifications'|'admin'; type Kind='all'|'portfolio'|'post';
const lang=ref<Lang>(ui.lang), dark=ref(false), tab=ref<Tab>('home'), kind=ref<Kind>('all'), search=ref(''), mobileMenu=ref(false), toast=ref(''), onboarding=ref(false), pageModal=ref(false), opportunityModal=ref(false), portfolioModal=ref(false), editingProfile=ref(false), selectedPerson=ref<number|string|null>(null), activeIdentity=ref('personal'), profileName=ref(tr('app.default_name')), profileBio=ref(''), profileCity=ref(''), profileSkills=ref(''), newPageName=ref(''), newPageCategory=ref(''), newPageCity=ref(''), newPageAbout=ref(''), newJobTitle=ref(''), newJobCity=ref(''), newJobDescription=ref(''), newPortfolioTitle=ref(''), newPortfolioDescription=ref(''), newPortfolioImage=ref(''), draftKind=ref<'post'|'portfolio'>('post');
type ProfessionalService={id:number|string;owner:string;title:string;description:string;price:string;image:string;raw?:ApiService};
const professionalServices=ref<ProfessionalService[]>([]);
const professionalHeadline=ref(''),professionalYears=ref(''),professionalCoverage=ref('');
const serviceEditor=ref(false),serviceTitle=ref(''),serviceDescription=ref(''),servicePrice=ref(''),serviceImage=ref('');
// Separate avatar and cover photos for each personal/business identity (prototype-local).
type IdentityPhotos={avatar?:string;cover?:string;coverY?:number};
const identityPhotos=ref<Record<string,IdentityPhotos>>({});
try { identityPhotos.value=JSON.parse(localStorage.getItem('kariva.identityPhotos.v1')||'{}') } catch { identityPhotos.value={} }
const currentPhotos=computed(()=>identityPhotos.value[activeIdentity.value]||{});
const coverMenu=ref(false),avatarMenu=ref(false);
const selectedBusinessProfile=ref<number|string|null>(null);
// V4.7: prototype-only favorites, comments and navigation. No authentication or backend.
const authScreen=ref<'login'|'register'|'forgot'|null>(null);
const authPhone=ref(''),authCode=ref(''),authName=ref(''),authStep=ref<1|2>(1);
const toLatinDigits=(v:string)=>v.replace(/[۰-۹]/g,d=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/[٠-٩]/g,d=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
function normalizePhone(raw:string){let v=toLatinDigits(raw).replace(/[\s\-()]/g,'');if(v.startsWith('+93'))v=v.slice(3);else if(v.startsWith('0093'))v=v.slice(4);else if(v.startsWith('93')&&v.length===11)v=v.slice(2);else if(v.startsWith('0'))v=v.slice(1);return /^7\d{8}$/.test(v)?'+93'+v:null}
function openAuth(kind:'login'|'register'){authScreen.value=kind;authStep.value=1;authCode.value='';authPhone.value=''}
const favorites=ref<string[]>([]);
try{favorites.value=JSON.parse(localStorage.getItem('kariva.favorites.v47')||'[]')}catch{}
const bioForPerson=(id:number|string)=>apiActors.value[String(id)]?.bio||'';


function openNotificationCenter(){tab.value='notifications';mobileMenu.value=false;window.scrollTo({top:0,behavior:'smooth'})}

const publicProfileSection=ref<'about'|'services'|'portfolio'>('about');
function openPersonProfile(id:number|string){selectedBusinessProfile.value=null;selectedPerson.value=id;publicProfileSection.value='about'}
function openBusinessProfile(id:number|string){selectedPerson.value=null;selectedBusinessProfile.value=id}
function closePhotoMenus(){coverMenu.value=false;avatarMenu.value=false}
function onOutsidePhotoMenu(e:PointerEvent){const target=e.target as HTMLElement;if(!target.closest('[data-photo-menu]'))closePhotoMenus()}
function onPhotoMenuEscape(e:KeyboardEvent){if(e.key==='Escape')closePhotoMenus()}
watch([tab,activeIdentity,selectedPerson,selectedBusinessProfile],closePhotoMenus);
onMounted(()=>{if(location.hash==='#/admin')tab.value='admin';document.addEventListener('pointerdown',onOutsidePhotoMenu);document.addEventListener('keydown',onPhotoMenuEscape)});
onUnmounted(()=>{document.removeEventListener('pointerdown',onOutsidePhotoMenu);document.removeEventListener('keydown',onPhotoMenuEscape)});

function sharePublicProfile(type:'person'|'business',id:number|string){const url=location.origin+location.pathname+'#'+type+'-'+id;if(navigator.share)navigator.share({title:'Kariva',url}).catch(()=>{});else navigator.clipboard?.writeText(url).then(()=>notify(tr('app.link_copied')))}

const photoEditor=ref(false),photoKind=ref<'avatar'|'cover'>('cover'),photoSource=ref(''),photoOwner=ref('personal');
const photoZoom=ref(1),photoX=ref(50),photoY=ref(50),photoSaving=ref(false);
const photoPreview=ref<HTMLElement|null>(null);
const photoPointer=ref(false);let pointerX=0,pointerY=0,startX=50,startY=50;
const photoTitle=computed(()=>photoKind.value==='cover'?tr('app.adjust_cover'):tr('app.adjust_avatar'));
const photoStyle=computed(()=>({objectPosition:`${photoX.value}% ${photoY.value}%`,transform:`scale(${photoZoom.value})`}));
function persistIdentityPhotos(){if(signedIn.value)return true;try{localStorage.setItem('kariva.identityPhotos.v1',JSON.stringify(identityPhotos.value));return true}catch{notify(tr('app.storage_full'));return false}}
function closePhotoEditor(){if(photoSaving.value)return;photoEditor.value=false;photoSource.value='';photoPointer.value=false}
function openPhotoEditor(kind:'avatar'|'cover',source?:string,owner=activeIdentity.value){const src=source||identityPhotos.value[owner]?.[kind];if(!src)return;photoKind.value=kind;photoOwner.value=owner;photoSource.value=src;photoZoom.value=1;photoX.value=50;photoY.value=50;coverMenu.value=false;avatarMenu.value=false;photoEditor.value=true}
function openCoverEditor(){openPhotoEditor('cover')}
function startPhotoPointer(e:PointerEvent){if(photoSaving.value)return;photoPointer.value=true;pointerX=e.clientX;pointerY=e.clientY;startX=photoX.value;startY=photoY.value;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)}
function movePhotoPointer(e:PointerEvent){if(!photoPointer.value)return;const el=e.currentTarget as HTMLElement;photoX.value=Math.max(0,Math.min(100,startX+(pointerX-e.clientX)*100/(el.clientWidth||300)));photoY.value=Math.max(0,Math.min(100,startY+(pointerY-e.clientY)*100/(el.clientHeight||200)))}
function endPhotoPointer(){photoPointer.value=false}
async function savePhotoCrop(){if(photoSaving.value)return;photoSaving.value=true;try{
  const img=new Image();img.src=photoSource.value;await img.decode();
  const isCover=photoKind.value==='cover';const width=isCover?1200:500,height=isCover?480:500;
  const canvas=document.createElement('canvas');canvas.width=width;canvas.height=height;const ctx=canvas.getContext('2d');if(!ctx)throw new Error('canvas');
  const scale=Math.max(width/img.naturalWidth,height/img.naturalHeight)*photoZoom.value;
  const drawW=img.naturalWidth*scale,drawH=img.naturalHeight*scale;
  const x=(width-drawW)*photoX.value/100,y=(height-drawH)*photoY.value/100;
  ctx.drawImage(img,x,y,drawW,drawH);
  const data=canvas.toDataURL('image/jpeg',.82);
  let shown=data;
  const actor=signedIn.value?actorIdOf(photoOwner.value):undefined;
  if(actor){
    const blob=await new Promise<Blob|null>(r=>canvas.toBlob(r,'image/jpeg',.82));if(!blob)throw new Error('blob');
    const up=await mediaApi.upload('actor',actor,photoKind.value,new File([blob],'photo.jpg',{type:'image/jpeg'}));
    shown=up.url??data;   // still being scanned: keep the local preview
  }
  const old={...identityPhotos.value[photoOwner.value]};const next={...old,[photoKind.value]:shown};
  identityPhotos.value={...identityPhotos.value,[photoOwner.value]:next};
  if(!persistIdentityPhotos()){identityPhotos.value={...identityPhotos.value,[photoOwner.value]:old};return}
  photoEditor.value=false;photoSource.value='';notify(tr('app.photo_saved'));
}catch(e){notify(e instanceof ApiError?errorText(e):tr('app.photo_save_failed'))}finally{photoSaving.value=false}}
const portfolioPreview=ref<string|null>(null);
function chooseIdentityPhoto(event:Event,kind:'avatar'|'cover',identity=activeIdentity.value){
 const input=event.target as HTMLInputElement;const file=input.files?.[0];if(!file)return;
 if(!file.type.startsWith('image/')){notify(tr('app.choose_image'));input.value='';return}
 if(file.size>10*1024*1024){notify(tr('app.image_too_large'));input.value='';return}
 const reader=new FileReader();reader.onload=()=>{openPhotoEditor(kind,String(reader.result),identity)};
 reader.onerror=()=>notify(tr('app.image_unreadable'));reader.readAsDataURL(file);input.value='';
}
async function removeIdentityPhoto(kind:'avatar'|'cover',identity=activeIdentity.value){const actor=signedIn.value?actorIdOf(identity):undefined;if(actor){try{await mediaApi.removeSlot(actor,kind)}catch(e){notify(errorText(e));return}}const item={...identityPhotos.value[identity]};delete item[kind];identityPhotos.value={...identityPhotos.value,[identity]:item};persistIdentityPhotos();notify(tr('app.photo_removed'))}
const ownServices=computed(()=>professionalServices.value.filter(x=>x.owner===activeIdentity.value));
function attachServiceImage(e:Event){const file=(e.target as HTMLInputElement).files?.[0];if(!file)return;if(!file.type.startsWith('image/')||file.size>5*1024*1024){notify(tr('app.photo_limit_5mb'));return}serviceFile.value=file;const reader=new FileReader();reader.onload=()=>serviceImage.value=String(reader.result||'');reader.readAsDataURL(file)}
function requestCatalogService(s:ProfessionalService){if(!s.raw){notify(tr('app.this_service_is_not_saved_yet'));return}if(!signedIn.value||!session.state.activeActorId){authScreen.value='login';return}requestOrder.value={serviceId:s.raw.id,name:activeIdentityName.value,title:s.title}}
onMounted(()=>{document.documentElement.lang=lang.value;document.documentElement.dir=lang.value==='en'?'ltr':'rtl';try{dark.value=localStorage.getItem('kariva-theme')==='dark'}catch{};document.documentElement.classList.toggle('dark',dark.value)});
const rtl=computed(()=>lang.value!=='en');
watch(lang,v=>{document.documentElement.lang=v;document.documentElement.dir=v==='en'?'ltr':'rtl'});watch(dark,v=>{document.documentElement.classList.toggle('dark',v);try{localStorage.setItem('kariva-theme',v?'dark':'light')}catch{}});
type Person={id:number|string,name:string,role:string,city:string,initials:string,gradient:string,followed:boolean,verified?:boolean};
const people=ref<Person[]>([]);
const personPhone=(id:number|string)=>apiActors.value[String(id)]?.contact_phone||apiActors.value[String(id)]?.whatsapp||'';
const waLink=(phone:string)=>'https://wa.me/'+phone.replace('+','');
const quickJobs:{icon:string;label:MessageKey;key:string}[]=[{icon:'⚡',label:'quick.electrician',key:'برق'},{icon:'🚰',label:'quick.plumber',key:'نل'},{icon:'🪚',label:'quick.carpenter',key:'نجار'},{icon:'🎨',label:'quick.painter',key:'رنگ'},{icon:'🧵',label:'quick.tailor',key:'خیاط'},{icon:'🍲',label:'quick.cook',key:'آشپز'},{icon:'🔧',label:'quick.mechanic',key:'مکانیک'},{icon:'💻',label:'quick.graphics',key:'گرافیک'}];
function goExplore(q:string){search.value=q;discoveryType.value='people';discoveryCity.value='all';discoveryCategory.value='all';tab.value='explore';window.scrollTo({top:0,behavior:'smooth'})}
function goNearMe(){search.value='';discoveryType.value='people';discoveryCategory.value='all';discoveryCity.value=profileCityId.value||'all';tab.value='explore';window.scrollTo({top:0,behavior:'smooth'})}
type Attachment={name:string,url:string,type:'image'|'video'|'document',size:number};
const postFiles=ref<Attachment[]>([]), portfolioFiles=ref<Attachment[]>([]), attachmentError=ref('');
function addFiles(event:Event,target:'post'|'portfolio'){
 const input=event.target as HTMLInputElement;const files=target==='post'?postFiles:portfolioFiles;attachmentError.value='';
 for(const file of Array.from(input.files||[])){
  if(files.value.length>=8){attachmentError.value=tr('app.max_8_files');break;}
  const type:Attachment['type']=file.type.startsWith('image/')?'image':file.type.startsWith('video/')?'video':'document';
  if(!(['image','video'].includes(type)&&type!=='document')&&!['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'].includes(file.type)){attachmentError.value=tr('app.file_type_not_allowed');continue;}
  if(file.size>20*1048576||files.value.reduce((n,a)=>n+a.size,0)+file.size>60*1048576){attachmentError.value=tr('app.file_too_large');continue;}
  files.value.push({name:file.name,url:URL.createObjectURL(file),type,size:file.size});
 }input.value='';
}
function removeFile(target:'post'|'portfolio',index:number){const a=target==='post'?postFiles:portfolioFiles;const [f]=a.value.splice(index,1);if(f)URL.revokeObjectURL(f.url);}
function discardFiles(target:'post'|'portfolio'){const a=target==='post'?postFiles:portfolioFiles;a.value.forEach(f=>URL.revokeObjectURL(f.url));a.value=[];attachmentError.value='';}
type BusinessPage={id:number|string,name:string,category:string,city:string,about:string,initials:string,managers:string[],verified?:boolean};
const businessPages=ref<BusinessPage[]>([]);
const identities=computed(()=>[{id:'personal',name:profileName.value},...businessPages.value.map(p=>({id:String(p.id),name:p.name}))]);
const activeIdentityName=computed(()=>identities.value.find(x=>x.id===activeIdentity.value)?.name??profileName.value);
const discoveryType=ref<'people'|'business'>('people'),discoveryCity=ref('all'),discoveryCategory=ref('all'),discoverySort=ref('relevant');
const discoveryQuery=computed(()=>search.value.trim().toLocaleLowerCase());
const discoveryPeople=computed(()=>people.value);
const discoveryBusinesses=computed(()=>businessResults.value);

// ---------------------------------------------------------------- live data (Phase 1): catalog, directory, my pages
const catalogCategories=ref<Category[]>([]),provinces=ref<Province[]>([]),apiActors=ref<Record<string,ApiActor>>({}),businessResults=ref<BusinessPage[]>([]),searching=ref(false),authBusy=ref(false),saving=ref(false);
const signedIn=session.signedIn,activeActor=session.active;
type Named={name:string;name_ps:string|null;name_en:string|null};
const nameOf=(x:Named|null|undefined)=>!x?'':(lang.value==='en'?x.name_en:lang.value==='ps'?x.name_ps:null)||x.name;
const allCities=computed(()=>provinces.value.flatMap(p=>p.cities));
const categoryList=computed(()=>catalogCategories.value.flatMap(r=>[r,...(r.children??[])]));
const leafCategories=computed(()=>catalogCategories.value.flatMap(r=>r.children?.length?r.children:[r]));
const cityByName=(name:string)=>allCities.value.find(c=>c.name===name||c.name_en===name)?.id??'';
const discoveryCities=allCities,discoveryCategories=categoryList;
const toPerson=(a:ApiActor):Person=>({id:a.id,name:a.display_name,role:nameOf(a.category),city:nameOf(a.city),initials:a.display_name.slice(0,1),gradient:'from-blue-400 to-cyan-600',followed:false,verified:a.verified});
const toPage=(a:ApiActor):BusinessPage=>({id:a.id,name:a.display_name,category:nameOf(a.category),city:nameOf(a.city),about:a.bio??'',initials:a.display_name.slice(0,1),managers:[],verified:a.verified});
let searchTimer:number|undefined;
async function runSearch(){
  searching.value=true;
  try{
    const base={q:search.value.trim()||undefined,category:discoveryCategory.value==='all'?undefined:discoveryCategory.value,city:discoveryCity.value==='all'?undefined:discoveryCity.value,limit:30};
    const [p,b]=await Promise.all([actorsApi.search({...base,type:'person'}),actorsApi.search({...base,type:'business'})]);
    for(const a of [...p.data,...b.data])apiActors.value[a.id]=a;
    const ph={...identityPhotos.value};for(const a of [...p.data,...b.data])ph[(a.type==='person'?'person:':'business:')+a.id]={avatar:a.avatar_url??undefined,cover:a.cover_url??undefined};identityPhotos.value=ph;
    people.value=p.data.map(toPerson);businessResults.value=b.data.map(toPage);
  }catch(e){notify(errorText(e))}finally{searching.value=false}
}
const scheduleSearch=()=>{window.clearTimeout(searchTimer);searchTimer=window.setTimeout(runSearch,250)};
async function loadCatalog(){try{[catalogCategories.value,provinces.value]=await Promise.all([catalog.categories(),catalog.provinces()]);if(!allCities.value.some(c=>c.id===newPageCity.value))newPageCity.value=cityByName('کابل')}catch(e){notify(errorText(e))}}
const profileCategoryId=ref(''),profileCityId=ref('');
/** Reads the profile of the personal actor and of every page of the user into the screens that still show prototype names. */
async function loadMine(){
  if(!session.user.value)return;
  const mine=session.actors.value;
  const full=await Promise.all(mine.map(a=>actorsApi.get(a.id).catch(()=>null)));
  {const ph={...identityPhotos.value};for(const a of full){if(!a)continue;const key=a.type==='person'?'personal':a.id;ph[key]={...ph[key],avatar:a.avatar_url??undefined,cover:a.cover_url??undefined}}identityPhotos.value=ph}
  const me=full.find(a=>a&&a.type==='person');
  if(me){apiActors.value[me.id]=me;profileName.value=me.display_name;profileBio.value=me.bio??'';profileCityId.value=me.city?.id??'';profileCategoryId.value=me.category?.id??'';profileCity.value=nameOf(me.city)||profileCity.value;profileSkills.value=nameOf(me.category)}
  businessPages.value=full.filter((a):a is ApiActor=>!!a&&a.type==='business').map(toPage);
  const known=activeIdentity.value==='personal'||businessPages.value.some(p=>String(p.id)===activeIdentity.value);
  if(!known)activeIdentity.value='personal';
  await loadMyInvites();
  if(myInvites.value.length)notify(tr('app.you_have_a_new_invitation_see'));
  await loadOwnServices();
}
function actorIdOf(identity:string){return identity==='personal'?session.personal.value?.id:identity}
async function loadOwnServices(){
  const id=actorIdOf(activeIdentity.value);if(!id||!session.user.value){professionalServices.value=[];return}
  try{professionalServices.value=(await servicesApi.list(id,true)).filter(s=>s.status!=='archived').map(s=>({id:s.id,owner:activeIdentity.value,title:s.title,description:s.description??'',price:priceText(s.pricing),image:s.images?.[0]??'',raw:s}));await loadPortfolio(id)}catch(e){notify(errorText(e))}
}
function priceText(p:{type:string;amount:string|null;currency:string|null;unit:string|null}){if(p.type==='negotiable'||!p.amount)return tr('app.negotiable');const price=money(p.amount,p.currency,p.unit);return p.type==='from'?tr('common.price_from',{price}):price}
const activePage=computed(()=>businessPages.value.find(p=>String(p.id)===activeIdentity.value));
const shownCity=computed(()=>activeIdentity.value==='personal'?profileCity.value:activePage.value?.city??'');
const shownRole=computed(()=>activeIdentity.value==='personal'?profileSkills.value:activePage.value?.category??'');
const shownBio=computed(()=>activeIdentity.value==='personal'?profileBio.value:activePage.value?.about??'');
watch([search,discoveryCity,discoveryCategory],scheduleSearch);
watch(activeIdentity,id=>{if(!session.user.value)return;const actor=actorIdOf(id);if(actor)session.switchTo(actor);loadOwnServices()});
watch(signedIn,async v=>{if(v)await loadMine();else{businessPages.value=[];professionalServices.value=[];portfolioItems.value=[];teamMembers.value=[];teamInvites.value=[];myInvites.value=[];activeIdentity.value='personal'}});
onMounted(async()=>{loadCatalog();runSearch();await session.restore()});

async function submitAuth(){
  if(authBusy.value)return;
  authBusy.value=true;
  try{
    if(authStep.value===1){
      const p=normalizePhone(authPhone.value);
      if(!p){notify(tr('app.phone_invalid_example'));return}
      await authApi.sendOtp(p);authPhone.value=p;authStep.value=2;return;
    }
    await session.verify(authPhone.value,toLatinDigits(authCode.value).replace(/\s/g,''));
    const personal=session.personal.value;
    if(authScreen.value==='register'&&authName.value.trim()&&personal&&personal.display_name.startsWith('کاربر '))await actorsApi.update(personal.id,{display_name:authName.value.trim()}).then(()=>session.refresh());
    authScreen.value=null;notify(tr('app.welcome'));
    await loadMine();
    if(!profileCategoryId.value||!profileCityId.value){editingProfile.value=true;notify(tr('app.please_add_your_name_profession_and'))}
  }catch(e){notify(errorText(e))}finally{authBusy.value=false}
}
async function signOut(){await session.logout();profileName.value=tr('app.default_name');profileBio.value='';profileCityId.value='';profileCategoryId.value='';profileSkills.value='';notify(tr('app.you_are_signed_out'))}
async function saveProfile(){
  const personal=session.personal.value;if(!personal){authScreen.value='login';editingProfile.value=false;return}
  saving.value=true;
  try{
    const a=await actorsApi.update(personal.id,{display_name:profileName.value.trim(),bio:profileBio.value.trim()||null,category_id:profileCategoryId.value||null,city_id:profileCityId.value||null});
    apiActors.value[a.id]=a;profileSkills.value=nameOf(a.category);profileCity.value=nameOf(a.city);await session.refresh();editingProfile.value=false;notify(tr('app.profile_saved'));scheduleSearch();
  }catch(e){notify(errorText(e))}finally{saving.value=false}
}
async function createPage(){
  if(!newPageName.value.trim()||!newPageCategory.value)return;
  if(!session.user.value){authScreen.value='login';return}
  saving.value=true;
  try{
    const a=await actorsApi.createPage({display_name:newPageName.value.trim(),category_id:newPageCategory.value,city_id:newPageCity.value||null,bio:newPageAbout.value.trim()||null});
    await session.refresh();await loadMine();activeIdentity.value=a.id;pageModal.value=false;tab.value='pages';newPageName.value='';newPageCategory.value='';newPageAbout.value='';notify(tr('app.page_created'));scheduleSearch();
  }catch(e){notify(errorText(e))}finally{saving.value=false}
}


// ---------------------------------------------------------------- Phase 2: orders, needs, chat, notices, reviews (live screens)
const UUID_RE=/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
/** Prototype keys look like "person:ID" or "business:ID"; the live ones carry an actor UUID. */
const actorOf=(key:string|number|null)=>{const part=String(key??'').split(':').pop()??'';return UUID_RE.test(part)?part:null};
const publicPortfolio=ref<{id:string;title:string;text:string;photo?:string}[]>([]),publicServices=ref<ApiService[]>([]);
const publicActorId=computed(()=>actorOf(selectedPerson.value??selectedBusinessProfile.value));
const publicBusiness=computed(()=>businessPages.value.find(p=>p.id===selectedBusinessProfile.value)??businessResults.value.find(p=>p.id===selectedBusinessProfile.value));
const publicVerified=computed(()=>!!apiActors.value[publicActorId.value??'']?.verified);
/** Anyone can read the services and work samples of a public profile; a late answer for another profile is dropped. */
watch(publicActorId,async actor=>{
  publicServices.value=[];publicPortfolio.value=[];
  if(!actor)return;
  try{
    const [list,items]=await Promise.all([servicesApi.list(actor),portfolioApi.list(actor)]);
    if(publicActorId.value!==actor)return;
    publicServices.value=list;publicPortfolio.value=items.map(i=>({id:i.id,title:i.title,text:i.description??'',photo:i.images[0]}));
  }catch(e){notify(errorText(e))}
});
const requestOrder=ref<{providerActorId?:string;serviceId?:string;name:string;title?:string}|null>(null);
const reviewsActor=ref<{id:string;name:string}|null>(null);
const chatOpenConv=ref<string|null>(null),chatOpenActor=ref<string|null>(null),chatKey=ref(0),ordersOpenId=ref<string|null>(null),ordersKey=ref(0);
function needSession(){if(session.user.value&&session.state.activeActorId)return true;authScreen.value='login';return false}
function requestPublicService(service:ApiService){
  const name=apiActors.value[publicActorId.value??'']?.display_name??'';
  selectedPerson.value=null;selectedBusinessProfile.value=null;
  if(!needSession())return;
  requestOrder.value={serviceId:service.id,name,title:service.title};
}
function openServiceRequest(id:string,name:string){
  const actor=actorOf(id);
  selectedPerson.value=null;selectedBusinessProfile.value=null;
  if(!actor){notify(tr('app.this_item_is_not_available'));return}
  if(!needSession())return;
  requestOrder.value={providerActorId:actor,name};
}
function openChat(id:string,_name?:string,_subtitle?:string){
  const actor=actorOf(id);
  if(!actor){notify(tr('app.this_item_is_not_available'));return}
  if(!needSession())return;
  chatOpenConv.value=null;chatOpenActor.value=actor;chatKey.value++;tab.value='messages';selectedPerson.value=null;selectedBusinessProfile.value=null;
}
function openConversation(id:string){chatOpenActor.value=null;chatOpenConv.value=id;chatKey.value++;tab.value='messages';requestOrder.value=null}
function openOrder(id:string){ordersOpenId.value=id;ordersKey.value++;tab.value='requests';window.scrollTo({top:0})}
function onOrderCreated(o:{id:string}){requestOrder.value=null;openOrder(o.id)}
function goNotice(n:ApiNotice){
  const d=n.data as Record<string,string>;
  if(n.type==='message.new'&&d.conversation_id)return openConversation(d.conversation_id);
  if(n.type==='offer.received'||n.type==='need.expired'||n.type==='offer.rejected'){tab.value='jobs';return}
  if(d.order_id)return openOrder(d.order_id);
  tab.value='requests';
}
function openReviews(target:string){
  const id=actorOf(target);
  if(!id){notify(tr('app.this_item_is_not_available'));return}
  reviewsActor.value={id,name:apiActors.value[id]?.display_name??''};
}
function toggleInlineReviews(target:string){openReviews(target)}
function ratingSummary(target:string){const a=apiActors.value[actorOf(target)??''];return a&&a.stats.rating_count?`${(a.stats.rating_avg??0).toFixed(1)} ★ · ${a.stats.rating_count}`:'☆ —'}
async function toggleFavorite(key:string){
  const id=actorOf(key);
  if(!id){favorites.value=favorites.value.includes(key)?favorites.value.filter(x=>x!==key):[...favorites.value,key];return}
  if(!signedIn.value){authScreen.value='login';return}
  const on=favorites.value.includes(key);
  try{if(on)await trust.removeFavorite('actor',id);else await trust.addFavorite('actor',id);favorites.value=on?favorites.value.filter(x=>x!==key):[...favorites.value,key]}catch(e){notify(errorText(e))}
}
async function loadFavorites(){if(!session.user.value)return;try{const list=await trust.favorites();favorites.value=list.filter(f=>f.type==='actor').flatMap(f=>['person:'+f.id,'business:'+f.id])}catch{/* keep local list */}}
const sideNeeds=ref<Need[]>([]);
async function loadSideNeeds(){try{sideNeeds.value=(await needsApi.board()).data.slice(0,2)}catch{/* the widget stays empty */}}
const unreadNotices=computed(()=>live.notices);
const unreadMessages=computed(()=>live.messages);
watch(lang,rememberLang,{immediate:true});
ui.toast=(m:string)=>notify(m);
watch(signedIn,v=>{if(v)void loadFavorites()});
onMounted(()=>{startLive();void loadSideNeeds()});

// ---------------------------------------------------------------- photos, services, work samples, team (Phase 1 UI)
const portfolioPicked=ref<{file:File;url:string}[]>([]);
function pickPortfolioImages(e:Event){
  const input=e.target as HTMLInputElement;
  for(const f of Array.from(input.files??[])){
    if(!['image/jpeg','image/png','image/webp'].includes(f.type)||f.size>5*1024*1024){notify(tr('app.only_jpg_png_or_webp_photos'));continue}
    if(portfolioPicked.value.length>=10){notify(tr('app.at_most_10_photos'));break}
    portfolioPicked.value.push({file:f,url:URL.createObjectURL(f)});
  }
  input.value='';
}
async function loadPortfolio(actorId:string){
  try{portfolioItems.value=(await portfolioApi.list(actorId)).map(i=>({id:i.id,title:i.title,text:i.description??'',photo:i.images[0],attachments:i.images.slice(1).map((u,n)=>({type:'image',url:u,name:'photo '+(n+2)}))}))}catch{portfolioItems.value=[]}
}
async function addPortfolio(){
  if(!newPortfolioTitle.value.trim())return;
  const actor=actorIdOf(activeIdentity.value);if(!actor||!session.user.value){authScreen.value='login';return}
  saving.value=true;
  try{
    const item=await portfolioApi.create(actor,{title:newPortfolioTitle.value.trim(),description:newPortfolioDescription.value.trim()||null});
    for(const p of portfolioPicked.value)await mediaApi.upload('portfolio_item',item.id,'images',p.file);
    portfolioPicked.value.forEach(p=>URL.revokeObjectURL(p.url));portfolioPicked.value=[];
    portfolioModal.value=false;newPortfolioTitle.value='';newPortfolioDescription.value='';
    await loadPortfolio(actor);notify(tr('app.work_sample_added'));
  }catch(e){notify(errorText(e))}finally{saving.value=false}
}
async function removePortfolio(id:string|number){
  const actor=actorIdOf(activeIdentity.value);if(!actor||!signedIn.value)return;
  try{await portfolioApi.remove(String(id));await loadPortfolio(actor)}catch(e){notify(errorText(e))}
}
function openServiceNew(){editServiceId.value=null;serviceTitle.value='';serviceDescription.value='';servicePrice.value='';serviceImage.value='';serviceFile.value=null;serviceMode.value='negotiable';serviceCategory.value='';serviceEditor.value=true}
function openServiceEdit(s:ProfessionalService){
  const r=s.raw;if(!r)return;
  editServiceId.value=r.id;serviceTitle.value=r.title;serviceDescription.value=r.description??'';serviceMode.value=r.pricing.type;
  servicePrice.value=r.pricing.amount?String(Number(r.pricing.amount)):'';serviceCurrency.value=(r.pricing.currency??'AFN') as 'AFN'|'USD';serviceUnit.value=r.pricing.unit??'project';
  serviceCategory.value=r.category?.id??'';serviceImage.value=s.image;serviceFile.value=null;serviceEditor.value=true;
}
async function archiveService(s:ProfessionalService){
  if(!s.raw){professionalServices.value=professionalServices.value.filter(x=>x.id!==s.id);return}
  if(!window.confirm(tr('app.delete_this_service')))return;
  try{await servicesApi.archive(s.raw.id);await loadOwnServices();scheduleSearch();notify(tr('app.service_deleted'))}catch(e){notify(errorText(e))}
}

const teamPageId=ref(''),teamMembers=ref<Member[]>([]),teamInvites=ref<Invitation[]>([]),myInvites=ref<Invitation[]>([]),invitePhone=ref(''),inviteRole=ref<Role>('editor'),transferPhone=ref('');
const roleLabel=(r:Role)=>tr(`role.${r}`);
const teamMe=computed(()=>session.actors.value.find(a=>a.id===teamPageId.value));
const canManage=computed(()=>['owner','admin'].includes(teamMe.value?.role??''));
const roleChoices=computed<Role[]>(()=>teamMe.value?.role==='owner'?['owner','admin','editor','viewer']:['admin','editor','viewer']);
const canEditMember=(m:Member)=>canManage.value&&!m.is_primary&&(teamMe.value?.role==='owner'||m.role!=='owner');
async function loadTeam(){
  if(!session.user.value)return;
  if(!businessPages.value.some(p=>String(p.id)===teamPageId.value))teamPageId.value=activeIdentity.value!=='personal'?activeIdentity.value:String(businessPages.value[0]?.id??'');
  const id=teamPageId.value;if(!id){teamMembers.value=[];teamInvites.value=[];return}
  try{teamMembers.value=await teamApi.members(id);teamInvites.value=canManage.value?await teamApi.invitations(id):[]}catch(e){notify(errorText(e))}
}
async function loadMyInvites(){if(session.user.value)myInvites.value=await teamApi.mine().catch(()=>[])}
async function afterTeamChange(){await session.refresh();await loadMine();await loadTeam()}
async function inviteMember(){
  saving.value=true;
  try{const p=normalizePhone(invitePhone.value);if(!p){notify(tr('app.the_number_is_not_valid'));return}
    await teamApi.invite(teamPageId.value,p,inviteRole.value);invitePhone.value='';await loadTeam();notify(tr('app.invitation_sent'))
  }catch(e){notify(errorText(e))}finally{saving.value=false}
}
async function cancelInvite(inv:Invitation){try{await teamApi.cancelInvitation(teamPageId.value,inv.id);await loadTeam()}catch(e){notify(errorText(e))}}
async function setMemberRole(m:Member,role:Role){try{await teamApi.setRole(teamPageId.value,m.user_id,role);await loadTeam()}catch(e){notify(errorText(e));await loadTeam()}}
async function removeMember(m:Member){
  const self=m.user_id===session.user.value?.id;
  if(!window.confirm(self?tr('app.leave_this_page'):tr('app.remove_this_member')))return;
  try{await teamApi.remove(teamPageId.value,m.user_id);if(self)await afterTeamChange();else await loadTeam()}catch(e){notify(errorText(e))}
}
async function respondInvite(inv:Invitation,accept:boolean){
  try{await teamApi.respond(inv.id,accept);await loadMyInvites();if(accept){await afterTeamChange();notify(tr('app.you_joined_the_team'))}}catch(e){notify(errorText(e));await loadMyInvites()}
}
async function startTransfer(){
  const p=normalizePhone(transferPhone.value);if(!p){notify(tr('app.the_number_is_not_valid'));return}
  if(!window.confirm(tr('app.send_a_primary_ownership_transfer_after')))return;
  try{await teamApi.invite(teamPageId.value,p,'owner','transfer_primary');transferPhone.value='';await loadTeam();notify(tr('app.transfer_invitation_sent'))}catch(e){notify(errorText(e))}
}
async function deletionStep(step:'request'|'confirm'){
  const id=teamPageId.value;
  if(step==='confirm'&&!window.confirm(tr('app.delete_this_page_for_good')))return;
  try{
    if(step==='request'){await teamApi.requestDeletion(id);notify(tr('app.deletion_requested_the_primary_owner_must'))}
    else{await teamApi.confirmDeletion(id);teamPageId.value='';await afterTeamChange();scheduleSearch();notify(tr('app.page_deleted'))}
  }catch(e){notify(errorText(e))}
}
watch(teamPageId,loadTeam);
watch(tab,v=>{if(v==='team'){loadMyInvites();loadTeam()}});

const serviceFile=ref<File|null>(null),editServiceId=ref<string|null>(null);
const serviceMode=ref<'negotiable'|'fixed'|'from'>('negotiable'),serviceCurrency=ref<'AFN'|'USD'>('AFN'),serviceUnit=ref('project'),serviceCategory=ref('');
async function addProfessionalService(){
  if(!serviceTitle.value.trim())return;
  const actor=actorIdOf(activeIdentity.value);if(!actor||!session.user.value){authScreen.value='login';return}
  const amount=toLatinDigits(servicePrice.value).replace(/[,٬\s]/g,'');
  if(serviceMode.value!=='negotiable'&&!(Number(amount)>0)){notify(tr('app.enter_the_price'));return}
  const neg=serviceMode.value==='negotiable';
  const input={title:serviceTitle.value.trim(),description:serviceDescription.value.trim()||null,pricing_type:serviceMode.value,price_amount:neg?null:amount,currency:neg?null:serviceCurrency.value,price_unit:neg?null:serviceUnit.value};
  saving.value=true;
  try{
    let id=editServiceId.value;
    if(id)await servicesApi.update(id,{...input,...(serviceCategory.value?{category_id:serviceCategory.value}:{})});
    else id=(await servicesApi.create(actor,{...input,category_id:serviceCategory.value||apiActors.value[actor]?.category?.id||leafCategories.value[0]?.id,delivery_mode:'onsite',status:'active'})).id;
    const uploaded=!!serviceFile.value;
    if(serviceFile.value)await mediaApi.upload('service',id,'gallery',serviceFile.value);
    if(uploaded)window.setTimeout(loadOwnServices,3000);   // the photo is shown once the background scan has marked it clean
    serviceEditor.value=false;serviceFile.value=null;editServiceId.value=null;serviceTitle.value='';serviceDescription.value='';servicePrice.value='';serviceImage.value='';serviceMode.value='negotiable';await loadOwnServices();notify(tr('app.service_saved'));scheduleSearch();
  }catch(e){notify(errorText(e))}finally{saving.value=false}
}

function notify(s:string){toast.value=s;setTimeout(()=>toast.value='',2600)}
function person(id:number|string){return people.value.find(p=>p.id===id)??{id:0,name:activeIdentityName.value,role:activeIdentity.value==='personal'?profileSkills.value:tr('app.business_page'),city:'',initials:activeIdentityName.value.slice(0,1),gradient:'from-blue-400 to-cyan-600',followed:false}}
const tabs=[{key:'home' as Tab,icon:Home},{key:'explore' as Tab,icon:Compass},{key:'jobs' as Tab,icon:BriefcaseBusiness},{key:'messages' as Tab,icon:MessageCircle},{key:'notifications' as Tab,icon:Bell},{key:'admin' as Tab,icon:ShieldBan},{key:'requests' as Tab,icon:CheckCheck},{key:'profile' as Tab,icon:UserRound},{key:'pages' as Tab,icon:Building2},{key:'portfolioManager' as Tab,icon:ImagePlus},{key:'skills' as Tab,icon:CheckCircle2},{key:'team' as Tab,icon:Users},{key:'settings' as Tab,icon:Settings2}];
function switchTab(k:Tab){tab.value=k;window.scrollTo({top:0})}
const URL=window.URL;
const mainTabs=(['home','jobs','requests','messages','profile'] as Tab[]).map(k=>tabs.find(x=>x.key===k)!);
const tabLabel=(key:Tab)=>tr(`tab.${key}`);
const portfolioItems=ref<{id:string|number;title:string;text:string;photo?:string;attachments:{type:string;url:string;name:string}[]}[]>([]);
const myPortfolio=computed(()=>portfolioItems.value);


const keyboardOpen=ref(false);
const viewportHeight=ref('100dvh');
const viewportTop=ref('0px');
let keyboardBaseline=0;
function updateMobileViewport(){
 const vv=window.visualViewport;
 if(!vv)return;
 const visible=vv.height;
 viewportHeight.value=`${visible}px`;
 viewportTop.value=`${vv.offsetTop}px`;
 // The layout viewport may not resize when the iOS keyboard appears.
 keyboardBaseline=Math.max(keyboardBaseline,window.innerHeight);
 keyboardOpen.value=keyboardBaseline-visible-vv.offsetTop>130;
}
function focusOrderInput(event:FocusEvent){
 updateMobileViewport();
 const field=event.target as HTMLElement;
 // iOS changes visualViewport asynchronously as the keyboard animates.
 [100,300,550].forEach(delay=>setTimeout(()=>{
  if(document.activeElement!==field)return;
  updateMobileViewport();
  field.scrollIntoView({block:'center',inline:'nearest',behavior:'smooth'});
 },delay));
}
function focusChatInput(event:FocusEvent){
 updateMobileViewport();
 nextTick(()=>setTimeout(()=>{
  updateMobileViewport();
  (event.target as HTMLElement)?.scrollIntoView({block:'nearest',behavior:'smooth'});
 },180));
}
function onGlobalFormFocus(e:FocusEvent){const el=e.target as HTMLElement;if(!el?.matches?.('input:not([type=checkbox]):not([type=radio]):not([type=file]), textarea, select'))return;if(window.innerWidth<768)focusOrderInput(e)}
onMounted(()=>document.addEventListener('focusin',onGlobalFormFocus));
onUnmounted(()=>document.removeEventListener('focusin',onGlobalFormFocus));
onMounted(()=>{updateMobileViewport();window.visualViewport?.addEventListener('resize',updateMobileViewport);window.visualViewport?.addEventListener('scroll',updateMobileViewport);window.addEventListener('orientationchange',resetMobileViewport)});
onUnmounted(()=>{window.visualViewport?.removeEventListener('resize',updateMobileViewport);window.visualViewport?.removeEventListener('scroll',updateMobileViewport);window.removeEventListener('orientationchange',resetMobileViewport)});
function resetMobileViewport(){keyboardBaseline=0;setTimeout(updateMobileViewport,150)}


function openAdmin(){tab.value='admin';try{location.hash='/admin'}catch{}window.scrollTo({top:0,behavior:'smooth'})}
function openSettings(){tab.value='settings';window.scrollTo({top:0,behavior:'smooth'})}

</script>
<template>
<div :dir="rtl?'rtl':'ltr'" :class="{dark}" class="kariva-shell min-h-screen bg-[#f6f8fc] text-slate-900 transition-colors dark:bg-[#080f1d] dark:text-slate-100">
  <header class="premium-header sticky top-0 z-30 border-b border-slate-200/70 bg-white/90 backdrop-blur-xl dark:border-slate-800 dark:bg-[#0c1527]/95"><div class="mx-auto flex h-[78px] max-w-[1450px] items-center gap-4 px-4 lg:px-8">
    <button class="flex shrink-0 items-center gap-2" @click="tab='home'"><span class="brand-mark grid h-11 w-11 place-items-center rounded-[15px] bg-gradient-to-br from-blue-600 to-cyan-500 text-xl font-black text-white shadow-lg shadow-blue-200 dark:shadow-none">K</span><span class="brand-wordmark text-2xl font-extrabold tracking-tight text-blue-700 dark:text-blue-400">kariva<span class="text-cyan-500">.</span></span></button>
    <div class="relative mx-auto hidden w-full max-w-[470px] md:block"><Search :size="19" class="soft absolute top-3" :class="rtl?'right-4':'left-4'"/><input v-model="search" :placeholder="tr('app.search')" class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800" :class="rtl?'pr-11 pl-4':'pl-11 pr-4'"/></div>
    <div class="ms-auto flex items-center gap-2"><button type="button" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold dark:border-slate-700" :aria-label="tabLabel('settings')" @click="tab='settings'"><Settings2 :size="18"/></button><button aria-label="Toggle theme" class="rounded-xl p-2.5 hover:bg-slate-100 dark:hover:bg-slate-800" @click="dark=!dark"><Sun v-if="dark" :size="21"/><Moon v-else :size="21"/></button><button :aria-label="tr('app.notifications')" class="relative shrink-0 rounded-xl p-2.5 hover:bg-slate-100 dark:hover:bg-slate-800" @click="openNotificationCenter"><Bell :size="21"/><span v-if="unreadNotices" class="absolute -right-1 -top-1 grid min-h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1 text-[10px] font-black text-white">{{unreadNotices>9?'9+':unreadNotices}}</span></button></div>
  </div></header>
  <div class="mx-auto grid max-w-[1450px] grid-cols-1 gap-7 px-4 pb-28 pt-7 md:grid-cols-[210px_minmax(0,1fr)] lg:grid-cols-[235px_minmax(0,700px)_290px] lg:px-8">
    <aside class="hidden md:block"><div class="sticky top-28 space-y-5"><nav class="card p-2"><button v-for="item in mainTabs" :key="item.key" class="nav-item w-full" :class="tab===item.key?'nav-on':'nav-off'" @click="tab=item.key"><component :is="item.icon" :size="20"/>{{tabLabel(item.key)}}<span v-if="item.key==='messages'&&unreadMessages" class="grid min-h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1 text-[10px] font-black text-white">{{unreadMessages>9?'9+':unreadMessages}}</span></button></nav><div class="card p-5"><div class="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-100 text-xl font-black text-blue-600">K</div><p class="font-bold">{{tr('app.tagline')}}</p><p class="soft mt-2 text-xs leading-6">{{profileBio}}</p></div><p class="soft px-3 text-xs">© 2026 Kariva</p></div></aside>
    <main class="min-w-0 space-y-5">
      <div class="relative md:hidden"><Search :size="19" class="soft absolute top-3" :class="rtl?'right-4':'left-4'"/><input v-model="search" :placeholder="tr('app.search')" class="w-full rounded-xl border border-slate-200 bg-white py-2.5 outline-none dark:border-slate-700 dark:bg-slate-900" :class="rtl?'pr-11 pl-4':'pl-11 pr-4'"/></div>
      <template v-if="tab==='home'"><div class="card flex flex-wrap items-center justify-between gap-3 p-4"><div><p class="font-extrabold">{{tr('app.tagline')}}</p><p class="soft mt-1 text-xs">{{tr('app.about')}}</p></div><div v-if="!signedIn" class="flex gap-2"><button class="chip chip-off" @click="openAuth('login')">{{tr('common.log_in')}}</button><button class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white" @click="openAuth('register')">{{tr('app.sign_up')}}</button></div></div>
      <section class="hero-premium relative isolate overflow-hidden rounded-[28px] p-6 text-white sm:p-9"><div class="relative z-10 space-y-5"><h1 class="text-[26px] font-black leading-[1.6] sm:text-[33px]">{{tr('app.who_do_you_need')}}</h1><form class="flex gap-2" @submit.prevent="goExplore(search)"><input v-model="search" class="min-w-0 flex-1 rounded-2xl border-0 px-4 py-4 text-base text-slate-900 outline-none" :placeholder="tr('app.e_g_electrician_tailor')" aria-label="search"/><button class="shrink-0 rounded-2xl bg-white px-5 text-xl font-extrabold text-blue-700" aria-label="search">🔍</button></form><div class="grid grid-cols-2 gap-3"><button class="rounded-2xl bg-white px-4 py-4 text-base font-extrabold text-blue-700" @click="goExplore('')">🔍 {{tr('app.i_need_a_specialist')}}</button><button class="rounded-2xl border border-white/40 bg-white/10 px-4 py-4 text-base font-extrabold" @click="tab='profile'">🧰 {{tr('app.i_want_work')}}</button></div></div></section>
      <div class="flex items-center justify-between gap-3"><h2 class="text-lg font-extrabold">{{tr('app.professions')}}</h2><button class="chip chip-off" @click="goNearMe">📍 {{tr('app.near_me')}}</button></div>
      <div class="grid grid-cols-3 gap-3 sm:grid-cols-4"><button v-for="j in quickJobs" :key="j.key" class="card flex min-h-[104px] flex-col items-center justify-center gap-2 p-3 text-center text-sm font-bold transition hover:shadow-lg" @click="goExplore(j.key)"><span class="text-4xl leading-none" aria-hidden="true">{{j.icon}}</span>{{tr(j.label)}}</button></div></template>
      <template v-else-if="tab==='explore'">
        <div class="space-y-2"><h1 class="text-2xl font-extrabold">{{tr('app.discovery_title')}}</h1><p class="soft text-sm">{{tr('app.discovery_intro')}}</p></div>
        <div class="card space-y-4 p-4 sm:p-5"><div class="flex flex-wrap gap-2"><button v-for="item in [{id:'people',label:tr('app.tab_people')},{id:'business',label:tr('app.tab_businesses')}]" :key="item.id" class="chip" :class="discoveryType===item.id?'chip-on':'chip-off'" @click="discoveryType=item.id as 'people'|'business'">{{item.label}}</button></div>
        <input v-model="search" class="v3-input" :placeholder="tr('app.search')" aria-label="Search discovery"/>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2"><label class="space-y-1 text-xs font-semibold"><span class="soft">{{tr('app.city_label')}}</span><select v-model="discoveryCity" class="v3-input"><option value="all">{{tr('needs.all_cities')}}</option><option v-for="city in discoveryCities" :key="city.id" :value="city.id">{{nameOf(city)}}</option></select></label><label class="space-y-1 text-xs font-semibold"><span class="soft">{{tr('app.profession_label')}}</span><select v-model="discoveryCategory" class="v3-input"><option value="all">{{tr('needs.all_professions')}}</option><option v-for="category in discoveryCategories" :key="category.id" :value="category.id">{{category.parent_id?'— ':''}}{{nameOf(category)}}</option></select></label></div>
        <div class="flex items-center justify-between gap-2"><p class="soft text-xs">{{discoveryType==='people'?discoveryPeople.length:discoveryBusinesses.length}} {{tr('app.results')}}</p><button class="text-xs font-bold text-blue-600 dark:text-blue-400" @click="search='';discoveryCity='all';discoveryCategory='all';discoverySort='relevant'">{{tr('app.clear_filters')}}</button></div></div>
        <div v-if="discoveryType==='people'" class="grid gap-4"><article v-for="p in discoveryPeople" :key="p.id" class="card professional-card cursor-pointer p-5 transition hover:shadow-lg" role="button" tabindex="0" :aria-label="tr('app.view_profile_of',{name:p.name})" @click="openPersonProfile(p.id)" @keydown.enter="openPersonProfile(p.id)"><div class="mb-3 flex items-center gap-3"><button class="grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-2xl bg-gradient-to-br font-extrabold text-white" :class="p.gradient" @click.stop="openPersonProfile(p.id)"><img v-if="identityPhotos['person:'+p.id]?.avatar" :src="identityPhotos['person:'+p.id]?.avatar" class="h-full w-full object-cover"/><span v-else>{{p.initials}}</span></button><div class="min-w-0"><button class="text-start font-extrabold hover:text-blue-600" @click.stop="openPersonProfile(p.id)">{{p.name}}<span v-if="p.verified" class="ms-1 text-emerald-600" :title="tr('app.verified')" :aria-label="tr('app.verified')">✔</span></button><p class="soft text-xs">{{p.role}}</p></div></div><p class="mb-3 text-sm leading-7 text-slate-600 dark:text-slate-300">{{bioForPerson(p.id)}}</p><p class="soft mb-3 flex items-center gap-1 text-xs"><MapPin :size="14"/>{{p.city}}</p><button class="mb-3 block rounded-lg bg-amber-50 px-3 py-2 text-sm font-bold text-amber-700 dark:bg-amber-950/30 dark:text-amber-300" @click.stop="openPersonProfile(p.id);toggleInlineReviews('person:'+p.id)">⭐ {{ratingSummary('person:'+p.id)}}</button><div class="grid grid-cols-2 gap-2"><a class="flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-3 py-4 text-base font-extrabold text-white" :href="'tel:'+personPhone(p.id)" @click.stop>📞 {{tr('app.call')}}</a><a class="flex items-center justify-center gap-2 rounded-2xl bg-teal-600 px-3 py-4 text-base font-extrabold text-white" :href="waLink(personPhone(p.id))" target="_blank" rel="noopener" @click.stop>💬 {{tr('app.whatsapp')}}</a></div><div class="mt-2 flex items-center gap-2"><button class="flex-1 rounded-xl bg-blue-600 px-3 py-2 text-sm font-bold text-white" @click.stop="openPersonProfile(p.id)">{{tr('app.view_profile')}}</button><button class="rounded-xl border border-blue-200 px-3 py-2 text-lg dark:border-blue-800" :aria-label="tr('app.favorite')" @click.stop="toggleFavorite('person:'+p.id)">{{favorites.includes('person:'+p.id)?'❤️':'🤍'}}</button></div></article></div>
        <div v-else-if="discoveryType==='business'" class="grid gap-4"><article v-for="p in discoveryBusinesses" :key="p.id" class="card cursor-pointer space-y-3 p-5 transition hover:shadow-lg" role="button" tabindex="0" @click="openBusinessProfile(p.id)" @keydown.enter="openBusinessProfile(p.id)"><div class="flex items-center gap-3"><button class="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-xl bg-blue-600 font-black text-white" @click.stop="openBusinessProfile(p.id)"><img v-if="identityPhotos['business:'+p.id]?.avatar" :src="identityPhotos['business:'+p.id]?.avatar" class="h-full w-full object-cover" alt=""/><span v-else>{{p.initials}}</span></button><div><button class="text-start font-extrabold hover:text-blue-600" @click.stop="openBusinessProfile(p.id)">{{p.name}}<span v-if="p.verified" class="ms-1 text-emerald-600" :title="tr('app.verified')" :aria-label="tr('app.verified')">✔</span></button><p class="soft text-xs">{{p.category}}</p></div></div><p class="soft text-sm"><MapPin :size="14" class="inline"/> {{p.city}}</p><p class="text-sm">{{p.about}}</p><button class="rounded-lg bg-amber-50 px-3 py-2 text-sm font-bold text-amber-700 dark:bg-amber-950/30 dark:text-amber-300" @click.stop="openBusinessProfile(p.id);toggleInlineReviews('business:'+p.id)">⭐ {{ratingSummary('business:'+p.id)}}</button><button class="block text-sm font-bold text-blue-600 dark:text-blue-400" @click.stop="openBusinessProfile(p.id)">{{tr('app.view_business_page')}} ←</button></article></div>
        <p v-if="(discoveryType==='people'&&!discoveryPeople.length)||(discoveryType==='business'&&!discoveryBusinesses.length)" class="card p-8 text-center soft">{{tr('app.empty')}}</p>
      </template>

      <template v-else-if="tab==='admin'"><AdminPanel v-if="session.user.value?.is_admin"/><p v-else class="soft py-12 text-center">{{tr('app.no_access')}}</p></template>
      <template v-else-if="tab==='notifications'"><NotificationsPanel @go="goNotice" @login="openAuth('login')"/></template>
      <template v-else-if="tab==='messages'"><ChatPanel :key="chatKey" :open-conversation-id="chatOpenConv" :open-with-actor="chatOpenActor" @login="openAuth('login')"/></template>
      <template v-else-if="tab==='requests'"><OrdersPanel :key="ordersKey" :open-id="ordersOpenId" @chat="openConversation" @login="openAuth('login')"/></template>
      <template v-else-if="tab==='jobs'"><NeedsBoard @chat="openConversation" @order="openOrder" @login="openAuth('login')"/></template>
      <template v-else-if="tab==='pages'"><div class="flex items-center justify-between gap-3"><div><h1 class="text-2xl font-extrabold">{{tr('app.business_pages')}}</h1><p class="soft mt-1 text-sm">{{tr('app.business_pages_intro')}}</p></div><button class="rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white" @click="pageModal=true">+ {{tr('app.new_page')}}</button></div><div class="grid gap-4 sm:grid-cols-2"><div v-for="p in businessPages" :key="p.id" class="card p-5"><button class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm font-bold text-amber-700 dark:bg-amber-950/30 dark:text-amber-300" @click="openReviews('business:'+p.id)">⭐ {{ratingSummary('business:'+p.id)}} · {{tr('app.ratings_and_reviews')}}</button><div class="mb-4 flex items-center gap-3"><div class="grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-600 to-cyan-500 text-2xl font-bold text-white"><img v-if="identityPhotos[String(p.id)]?.avatar" :src="identityPhotos[String(p.id)]?.avatar" class="h-full w-full object-cover" :alt="tr('app.page_logo')"/><span v-else>{{p.initials}}</span></div><div><h3 class="font-extrabold">{{p.name}}<span v-if="p.verified" class="ms-1 text-emerald-600" :title="tr('app.verified')" :aria-label="tr('app.verified')">✔</span></h3><p class="soft text-xs">{{p.category}}</p></div></div><p class="soft text-sm"><MapPin :size="14" class="inline"/> {{p.city}}</p><p class="mt-3 text-sm leading-7">{{p.about}}</p><div v-if="identityPhotos[String(p.id)]?.cover" class="mt-3 h-24 overflow-hidden rounded-xl"><img :src="identityPhotos[String(p.id)]?.cover" class="h-full w-full object-cover" :alt="tr('app.page_cover')"/></div><div class="mt-3 flex flex-wrap gap-2"><label class="cursor-pointer rounded-lg border border-blue-300 px-3 py-2 text-xs font-bold text-blue-600 dark:border-blue-700">📷 {{tr('app.page_photo')}}<input type="file" accept="image/*" class="sr-only" @change="chooseIdentityPhoto($event,'avatar',String(p.id))"/></label><label class="cursor-pointer rounded-lg border border-blue-300 px-3 py-2 text-xs font-bold text-blue-600 dark:border-blue-700">🖼️ {{tr('app.page_cover')}}<input type="file" accept="image/*" class="sr-only" @change="chooseIdentityPhoto($event,'cover',String(p.id))"/></label></div><div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800"><span class="soft text-xs">{{p.managers.length}} {{tr('app.manager_count')}}</span><button class="rounded-xl bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300" @click="activeIdentity=String(p.id);notify(tr('app.acting_for',{name:p.name}))">{{activeIdentity===String(p.id)?tr('app.selected'):tr('app.select_page')}}</button></div></div></div></template>

      <template v-else-if="tab==='portfolioManager'"><div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-extrabold">{{tabLabel('portfolioManager')}}</h1><button class="v3-primary !w-auto px-5" @click="portfolioModal=true">+ {{tr('app.portfolio')}}</button></div><div v-if="!myPortfolio.length" class="card p-8 text-center soft">{{tr('app.add_first_work')}}</div><div class="grid gap-4 sm:grid-cols-2"><article v-for="p in myPortfolio" :key="p.id" class="card overflow-hidden"><img v-if="p.photo" :src="p.photo" class="h-44 w-full cursor-zoom-in object-cover" @click="portfolioPreview=p.photo"/><div v-if="p.attachments?.length" class="grid grid-cols-2 gap-2 p-3"><div v-for="(a,i) in p.attachments" :key="i"><img v-if="a.type==='image'" :src="a.url" class="h-28 w-full cursor-zoom-in object-cover" @click="portfolioPreview=a.url"/><video v-else-if="a.type==='video'" :src="a.url" controls class="h-28 w-full"/><a v-else :href="a.url" :download="a.name" class="text-xs text-blue-500">📎 {{a.name}}</a></div></div><div class="p-5"><h3 class="font-bold">{{p.title}}</h3><p class="soft mt-2 text-sm">{{p.text}}</p><button class="mt-4 text-sm font-bold text-rose-500" @click="removePortfolio(p.id)"><Trash2 :size="15" class="inline"/> {{tr('app.remove')}}</button></div></article></div></template>
      <template v-else-if="tab==='team'"><h1 class="text-2xl font-extrabold">{{tabLabel('team')}}</h1>
        <div v-if="myInvites.length" class="card space-y-3 border-blue-300 p-5"><h2 class="font-extrabold">{{tr('app.your_invitations')}}</h2><div v-for="inv in myInvites" :key="inv.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-blue-50 p-3 dark:bg-slate-800"><div><p class="font-bold">{{inv.actor?.display_name}}</p><p class="soft text-xs">{{inv.kind==='transfer_primary'?tr('app.primary_ownership_transfer'):roleLabel(inv.role)}}</p></div><div class="flex gap-2"><button class="v3-primary !w-auto px-4 !py-2" @click="respondInvite(inv,true)">{{tr('app.accept')}}</button><button class="rounded-xl border px-4 py-2 text-sm dark:border-slate-700" @click="respondInvite(inv,false)">{{tr('common.decline')}}</button></div></div></div>
        <div v-if="!signedIn" class="card space-y-3 p-6 text-center"><p class="soft text-sm">{{tr('app.log_in_to_manage_your_team')}}</p><button class="v3-primary !w-auto px-6" @click="openAuth('login')">{{tr('common.log_in')}}</button></div>
        <div v-else-if="!businessPages.length" class="card space-y-3 p-6 text-center"><p class="soft text-sm">{{tr('app.teams_belong_to_business_pages_create')}}</p><button class="v3-primary !w-auto px-6" @click="pageModal=true">+ {{tr('app.new_page')}}</button></div>
        <div v-else class="card space-y-4 p-5 sm:p-6">
          <select v-model="teamPageId" class="v3-input"><option v-for="p in businessPages" :key="p.id" :value="String(p.id)">{{p.name}}<span v-if="p.verified" class="ms-1 text-emerald-600" :title="tr('app.verified')" :aria-label="tr('app.verified')">✔</span></option></select>
          <p class="soft text-xs">{{tr('app.your_role')}} <b>{{teamMe?roleLabel(teamMe.role):''}}</b><span v-if="teamMe?.is_primary"> · {{tr('app.primary_owner')}}</span></p>
          <div v-for="m in teamMembers" :key="m.user_id" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700"><div class="min-w-0"><p class="font-bold">{{m.display_name||'—'}}<span v-if="m.user_id===session.user.value?.id" class="soft text-xs"> ({{tr('app.you')}})</span></p><p v-if="m.phone" class="soft text-xs" dir="ltr">{{m.phone}}</p></div><div class="flex items-center gap-2"><span v-if="m.is_primary" class="chip chip-on">{{tr('app.primary_owner')}}</span><select v-else-if="canEditMember(m)" :value="m.role" class="v3-input !w-auto !py-1" @change="setMemberRole(m,($event.target as HTMLSelectElement).value as Role)"><option v-for="r in roleChoices" :key="r" :value="r">{{roleLabel(r)}}</option></select><span v-else class="chip chip-off">{{roleLabel(m.role)}}</span><button v-if="!m.is_primary&&(canEditMember(m)||m.user_id===session.user.value?.id)" class="text-sm text-rose-500" :aria-label="tr('app.remove')" @click="removeMember(m)"><Trash2 :size="17"/></button></div></div>
          <form v-if="canManage" class="space-y-2 border-t border-slate-100 pt-4 dark:border-slate-800" @submit.prevent="inviteMember"><h3 class="font-extrabold">{{tr('app.invite_a_member')}}</h3><div class="flex flex-wrap gap-2"><input v-model="invitePhone" required dir="ltr" inputmode="tel" class="v3-input min-w-0 flex-1" placeholder="0771234567"/><select v-model="inviteRole" class="v3-input !w-auto"><option v-for="r in roleChoices" :key="r" :value="r">{{roleLabel(r)}}</option></select><button :disabled="saving" class="v3-primary !w-auto px-5 disabled:opacity-60">{{tr('app.invite')}}</button></div><p class="soft text-xs">{{tr('app.the_invitation_lasts_7_days_and')}}</p></form>
          <div v-if="canManage&&teamInvites.length" class="space-y-2"><h3 class="font-extrabold">{{tr('app.pending_invitations')}}</h3><div v-for="inv in teamInvites" :key="inv.id" class="flex items-center justify-between rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800"><span dir="ltr">{{inv.phone}}</span><span class="soft text-xs">{{inv.kind==='transfer_primary'?tr('app.transfer'):roleLabel(inv.role)}}</span><button class="text-xs text-rose-500" @click="cancelInvite(inv)">{{tr('app.cancel')}}</button></div></div>
          <div v-if="teamMe?.is_primary" class="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800"><h3 class="font-extrabold">{{tr('app.ownership_and_deletion')}}</h3><form class="flex flex-wrap gap-2" @submit.prevent="startTransfer"><input v-model="transferPhone" required dir="ltr" inputmode="tel" class="v3-input min-w-0 flex-1" placeholder="0771234567"/><button class="rounded-xl border border-blue-300 px-4 py-2 text-sm font-bold text-blue-600">{{tr('app.transfer_primary_ownership')}}</button></form><div class="flex flex-wrap gap-2"><button class="rounded-xl border border-amber-300 px-4 py-2 text-sm font-bold text-amber-700" @click="deletionStep('request')">{{tr('app.request_page_deletion')}}</button><button class="rounded-xl border border-rose-300 px-4 py-2 text-sm font-bold text-rose-600" @click="deletionStep('confirm')">{{tr('app.confirm_deletion')}}</button></div></div>
          <button v-else-if="teamMe?.role==='owner'" class="rounded-xl border border-amber-300 px-4 py-2 text-sm font-bold text-amber-700" @click="deletionStep('request')">{{tr('app.request_page_deletion_the_primary_owner')}}</button>
        </div></template>
      <template v-else-if="tab==='settings'"><h1 class="text-2xl font-extrabold">{{tabLabel('settings')}}</h1><div class="card grid gap-2 p-4 sm:grid-cols-2"><button v-for="k in (['notifications','pages','portfolioManager','team'] as Tab[])" :key="k" type="button" class="rounded-xl border border-slate-200 px-4 py-3 text-start text-sm font-bold dark:border-slate-700" @click="tab=k">{{k==='notifications'&&unreadNotices?'🔔 ':''}}{{tabLabel(k)}}</button></div><div class="card space-y-5 p-4 sm:p-6"><button v-if="session.user.value?.is_admin" type="button" class="flex w-full items-center justify-between gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-start font-bold text-blue-800 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-200" data-testid="open-admin" @click="openAdmin"><span>🛡️ {{tr('app.admin_panel')}}</span><span aria-hidden="true">↗</span></button><button v-if="signedIn" type="button" class="rounded-xl border border-rose-300 px-4 py-2 text-sm font-bold text-rose-600" @click="signOut">{{tr('app.sign_out')}}</button><button v-else type="button" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white" @click="openAuth('login')">{{tr('app.log_in_sign_up')}}</button><label class="block font-bold">{{tr('app.active_identity_page')}}</label><select v-model="activeIdentity" class="v3-input"><option v-for="identity in identities" :key="identity.id" :value="identity.id">{{identity.name}}</option></select><button type="button" class="rounded-xl border border-blue-300 px-4 py-2 text-sm font-bold text-blue-600" @click="tab='pages'">{{tr('app.manage_and_create_business_pages')}}</button><label class="block font-bold">{{tr('app.app_language')}}</label><select v-model="lang" class="v3-input"><option value="fa">دری</option><option value="ps">پښتو</option><option value="en">English</option></select><label class="flex items-center justify-between gap-4 font-bold"><span>{{tr('app.dark_mode')}}</span><input v-model="dark" type="checkbox" class="h-5 w-5"/></label><VerificationPanel v-if="signedIn"/><button class="v3-primary !w-auto px-5" @click="tab='profile';editingProfile=true">{{tr('app.edit_account')}}</button></div></template>
      <template v-else><div class="card overflow-hidden"><div class="profile-cover relative h-44 bg-gradient-to-l from-blue-700 to-cyan-500">
<img v-if="currentPhotos.cover" :src="currentPhotos.cover" :alt="tr('app.cover_photo')" class="h-full w-full object-cover" />
<div class="absolute bottom-3 end-3 z-10">
<button type="button" data-photo-menu :aria-label="tr('app.manage_cover')" :aria-expanded="coverMenu" class="grid h-10 w-10 place-items-center rounded-full bg-slate-950/80 text-white shadow" @click="coverMenu=!coverMenu;avatarMenu=false"><Camera :size="19"/></button>
<div v-if="coverMenu" data-photo-menu class="absolute bottom-12 end-0 z-20 w-52 space-y-1 rounded-xl bg-white p-2 text-right text-sm text-slate-900 shadow-xl dark:bg-slate-800 dark:text-white">
<label class="block cursor-pointer rounded-lg px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-700">{{tr('app.change_cover')}}<input type="file" accept="image/*" class="sr-only" @change="chooseIdentityPhoto($event,'cover')"/></label>
<button v-if="currentPhotos.cover" type="button" class="block w-full rounded-lg px-3 py-2 text-right hover:bg-slate-100 dark:hover:bg-slate-700" @click="openCoverEditor">↕ {{tr('app.adjust_cover_short')}}</button>
<button v-if="currentPhotos.cover" type="button" class="block w-full rounded-lg px-3 py-2 text-right text-rose-500" @click="removeIdentityPhoto('cover');coverMenu=false">{{tr('app.remove_cover')}}</button>
</div></div>
</div><div class="p-6"><div class="relative -mt-14 h-24 w-24"><div class="grid h-24 w-24 place-items-center overflow-hidden rounded-2xl border-4 border-white bg-blue-100 text-3xl font-black text-blue-700 dark:border-slate-900"><img v-if="currentPhotos.avatar" :src="currentPhotos.avatar" :alt="tr('app.profile_photo')" class="h-full w-full object-cover"/><span v-else>{{activeIdentityName.slice(0,1)}}</span></div><button type="button" data-photo-menu :aria-label="tr('app.manage_profile_photo')" :aria-expanded="avatarMenu" class="absolute -bottom-2 -end-2 grid h-9 w-9 place-items-center rounded-full bg-blue-600 text-white shadow" @click="avatarMenu=!avatarMenu;coverMenu=false"><Camera :size="17"/></button><div v-if="avatarMenu" data-photo-menu class="absolute start-0 top-full z-30 mt-3 w-48 max-w-[calc(100vw-3rem)] rounded-xl bg-white p-2 text-right text-sm text-slate-900 shadow-xl dark:bg-slate-800 dark:text-white"><label class="block cursor-pointer rounded-lg px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-700">{{tr('app.change_avatar')}}<input type="file" accept="image/*" class="sr-only" @change="chooseIdentityPhoto($event,'avatar');avatarMenu=false"/></label><button v-if="currentPhotos.avatar" type="button" class="block w-full rounded-lg px-3 py-2 text-right text-rose-500" @click="removeIdentityPhoto('avatar');avatarMenu=false">{{tr('app.remove_avatar')}}</button></div></div><h1 class="mt-3 text-2xl font-extrabold">{{activeIdentityName}}</h1><p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm leading-7 dark:bg-slate-800">{{activeIdentity==='personal'?profileBio:businessPages.find(p=>String(p.id)===activeIdentity)?.about}}</p><p class="soft mt-4 flex items-center gap-2 text-sm"><MapPin :size="17"/>{{shownCity}} · {{shownRole}}</p><div class="mt-6 flex flex-wrap gap-2"><button class="rounded-xl bg-blue-600 px-5 py-2.5 font-bold text-white" @click="editingProfile=true">✎ {{tr('app.edit_profile')}}</button><button class="rounded-xl border border-slate-200 px-5 py-2.5 font-bold dark:border-slate-700" @click="portfolioModal=true">+ {{tr('app.work_sample')}}</button></div><section class="mt-5 rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><h3 class="mb-3 font-extrabold">♡ {{tr('app.my_favorites')}}</h3><p v-if="!favorites.length" class="soft text-sm">{{tr('app.no_saved_profiles_yet')}}</p><div class="flex flex-wrap gap-2"><button v-for="id in favorites" :key="id" class="chip chip-off" @click="id.startsWith('person:')?openPersonProfile(Number(id.split(':')[1])):openBusinessProfile(Number(id.split(':')[1]))">{{id.startsWith('person:')?person(Number(id.split(':')[1])).name:businessPages.find(p=>p.id===Number(id.split(':')[1]))?.name}} ♡</button></div></section><div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-4"><button v-for="item in tabs.filter(x=>['portfolioManager','skills','team','settings'].includes(x.key))" :key="item.key" class="rounded-xl border border-slate-200 p-3 text-sm font-bold dark:border-slate-700" @click="tab=item.key"><component :is="item.icon" :size="18" class="mx-auto mb-2"/>{{tabLabel(item.key)}}</button></div></div></div>
      <section class="card mt-4 space-y-5 p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-extrabold">✨ {{tr('app.professional_profile')}} · {{activeIdentityName}}</h2></div></div>
        <div class="space-y-4"><div class="flex items-center justify-between"><h3 class="font-extrabold">{{tr('app.services_offered')}}</h3><button class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white" @click="openServiceNew">+ {{tr('app.add_service')}}</button></div><p v-if="!ownServices.length" class="soft rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm dark:border-slate-700">{{tr('app.no_services_own')}}</p><div class="grid gap-3 sm:grid-cols-2"><article v-for="service in ownServices" :key="service.id" class="overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-700"><img v-if="service.image" :src="service.image" :alt="service.title" class="h-36 w-full object-cover"/><div class="space-y-2 p-4"><h4 class="font-extrabold">{{service.title}}</h4><p class="soft whitespace-pre-wrap text-sm">{{service.description}}</p><p class="font-bold text-blue-600">{{service.price}}</p><div class="flex flex-wrap gap-2"><button class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white" @click="requestCatalogService(service)">{{tr('app.request_service')}}</button><button class="rounded-lg border border-rose-300 px-3 py-2 text-xs text-rose-500" @click="archiveService(service)">{{tr('app.delete')}}</button><button v-if="service.raw" class="rounded-lg border border-blue-300 px-3 py-2 text-xs text-blue-600" @click="openServiceEdit(service)">{{tr('app.edit')}}</button></div></div></article></div></div>
      </section></template>
    </main>
    <aside class="hidden lg:block"><div class="sticky top-28 space-y-5"><div class="card p-5"><h3 class="mb-4 flex items-center gap-2 font-extrabold"><Users :size="19" class="text-blue-600"/>{{tr('app.discover')}}</h3><div v-for="p in people.slice(0,3)" :key="p.id" class="flex items-center gap-3 border-t border-slate-100 py-3 first:border-0 dark:border-slate-800"><div class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-gradient-to-br text-xs font-bold text-white" :class="p.gradient">{{p.initials}}</div><div class="min-w-0 flex-1"><p class="truncate text-sm font-bold">{{p.name}}<span v-if="p.verified" class="ms-1 text-emerald-600" :title="tr('app.verified')" :aria-label="tr('app.verified')">✔</span></p><p class="soft truncate text-xs">{{p.role}}</p></div></div><button class="mt-2 text-sm font-bold text-blue-600" @click="tab='explore'">{{tr('app.explore')}} ←</button></div><div class="card p-5"><h3 class="mb-4 font-extrabold">💼 {{tr('app.opportunities')}}</h3><p v-if="!sideNeeds.length" class="soft text-xs">{{tr('app.no_needs_yet')}}</p><button v-for="n in sideNeeds" :key="n.id" class="block w-full border-t border-slate-100 py-3 text-start first:border-0 dark:border-slate-800" @click="tab='jobs'"><p class="text-sm font-bold">{{n.title}}</p><p class="soft mt-1 text-xs">{{n.actor.display_name}}<span v-if="n.city"> · {{nameOf(n.city)}}</span></p></button></div></div></aside>
  </div>
  <div v-if="tab==='profile'" class="fixed bottom-[74px] end-4 z-20 md:hidden"><button type="button" class="flex items-center gap-2 rounded-full border border-blue-200 bg-white px-4 py-2.5 text-xs font-extrabold text-blue-700 shadow-lg dark:border-blue-800 dark:bg-slate-900 dark:text-blue-300" @click="openSettings"><Settings2 :size="17"/>{{tabLabel('settings')}}</button></div>
  <nav class="fixed bottom-0 z-30 flex w-full items-center justify-around border-t border-slate-200 bg-white/95 px-2 py-2 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95 md:hidden" aria-label="main"><button v-for="item in mainTabs" :key="item.key" class="flex min-h-[56px] min-w-[58px] flex-col items-center justify-center gap-1 px-1 py-1 text-[11px] font-bold" :class="tab===item.key?'text-blue-600':'soft'" @click="switchTab(item.key)"><component :is="item.icon" :size="24"/>{{tabLabel(item.key)}}<span v-if="item.key==='messages'&&unreadMessages" class="grid min-h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1 text-[10px] font-black text-white">{{unreadMessages>9?'9+':unreadMessages}}</span></button></nav>
  <div v-if="photoEditor" class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/85 p-4" @click.self="closePhotoEditor">
    <div class="w-full max-w-xl rounded-2xl bg-white p-5 text-slate-900 shadow-2xl dark:bg-slate-900 dark:text-white" dir="rtl">
      <div class="mb-4 flex items-center justify-between"><h2 class="font-extrabold">{{photoTitle}}</h2><button type="button" :disabled="photoSaving" @click="closePhotoEditor"><X :size="22"/></button></div>
      <p class="soft mb-3 text-sm">{{tr('app.photo_editor_hint')}}</p>
      <div ref="photoPreview" class="relative mx-auto touch-none overflow-hidden rounded-xl bg-slate-200 dark:bg-slate-800" :class="photoKind==='cover'?'aspect-[5/2] w-full':'aspect-square w-64 max-w-full'" style="cursor:grab" @pointerdown="startPhotoPointer" @pointermove="movePhotoPointer" @pointerup="endPhotoPointer" @pointercancel="endPhotoPointer">
        <img :src="photoSource" draggable="false" class="pointer-events-none h-full w-full select-none object-cover" :style="photoStyle"/>
      </div>
      <div class="mt-5 flex items-center gap-3"><span class="text-sm">−</span><input v-model.number="photoZoom" :aria-label="tr('app.zoom')" type="range" min="1" max="3" step="0.05" class="w-full accent-blue-600"/><span class="text-sm">+</span><span class="w-12 text-left text-xs">{{Math.round(photoZoom*100)}}%</span></div>
      <div class="mt-4 flex justify-end gap-2"><button :disabled="photoSaving" class="rounded-xl border px-4 py-2 dark:border-slate-700" @click="closePhotoEditor">{{tr('app.cancel_action')}}</button><button :disabled="photoSaving" class="flex min-w-36 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-2 font-bold text-white disabled:opacity-70" @click="savePhotoCrop"><span v-if="photoSaving" class="kariva-spinner"></span>{{photoSaving?tr('app.saving'):tr('app.save_photo')}}</button></div>
    </div>
  </div>
  <div v-if="portfolioPreview" class="fixed inset-0 z-[130] flex items-center justify-center bg-black/95 p-4" @click.self="portfolioPreview=null"><button type="button" class="absolute end-5 top-5 rounded-full bg-white/20 p-3 text-white" @click="portfolioPreview=null"><X :size="24"/></button><img :src="portfolioPreview" class="max-h-[88dvh] max-w-full object-contain" :alt="tr('app.full_work_view')"/></div>
  <div v-if="pageModal" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/65 p-4" @click.self="pageModal=false"><form class="card w-full max-w-lg space-y-4 p-6" @submit.prevent="createPage"><div class="flex items-center justify-between"><h2 class="text-xl font-extrabold">{{tr('app.create_business_page')}}</h2><button type="button" @click="pageModal=false"><X/></button></div><input v-model="newPageName" required :placeholder="tr('app.company_name_placeholder')" class="v3-input"/><select v-model="newPageCategory" required class="v3-input"><option value="">{{tr('app.type_of_work')}}</option><optgroup v-for="root in catalogCategories" :key="root.id" :label="nameOf(root)"><option v-for="c in (root.children?.length?root.children:[root])" :key="c.id" :value="c.id">{{nameOf(c)}}</option></optgroup></select><select v-model="newPageCity" class="v3-input"><optgroup v-for="p in provinces" :key="p.id" :label="nameOf(p)"><option v-for="c in p.cities" :key="c.id" :value="c.id">{{nameOf(c)}}</option></optgroup></select><textarea v-model="newPageAbout" rows="3" :placeholder="tr('app.about_business_placeholder')" class="v3-input"></textarea><button type="submit" :disabled="saving" class="v3-primary disabled:opacity-60">{{tr('app.create_page')}}</button></form></div>

  <div v-if="serviceEditor" class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-3" @click.self="serviceEditor=false"><form class="card max-h-[90dvh] w-full max-w-lg space-y-3 overflow-y-auto p-5" @submit.prevent="addProfessionalService"><div class="flex items-center justify-between"><h2 class="text-xl font-extrabold">{{editServiceId?tr('app.edit_service'):tr('app.new_service')}}</h2><button type="button" @click="serviceEditor=false"><X/></button></div><input v-model="serviceTitle" required maxlength="120" class="v3-input" :placeholder="tr('app.service_title_placeholder')"/><textarea v-model="serviceDescription" rows="3" class="v3-input" :placeholder="tr('app.service_desc_placeholder')"></textarea><select v-model="serviceCategory" class="v3-input"><option value="">{{tr('app.same_as_my_profession')}}</option><optgroup v-for="root in catalogCategories" :key="root.id" :label="nameOf(root)"><option v-for="c in (root.children?.length?root.children:[root])" :key="c.id" :value="c.id">{{nameOf(c)}}</option></optgroup></select><div class="flex gap-2"><button type="button" class="chip" :class="serviceMode==='negotiable'?'chip-on':'chip-off'" @click="serviceMode='negotiable'">{{tr('app.negotiable')}}</button><button type="button" class="chip" :class="serviceMode==='fixed'?'chip-on':'chip-off'" @click="serviceMode='fixed'">{{tr('app.fixed')}}</button><button type="button" class="chip" :class="serviceMode==='from'?'chip-on':'chip-off'" @click="serviceMode='from'">{{tr('app.from')}}</button></div><div v-if="serviceMode!=='negotiable'" class="grid grid-cols-3 gap-2"><input v-model="servicePrice" inputmode="decimal" dir="ltr" class="v3-input" :placeholder="tr('common.amount')"/><select v-model="serviceCurrency" class="v3-input"><option value="AFN">{{tr('common.afn')}}</option><option value="USD">{{tr('common.usd')}}</option></select><select v-model="serviceUnit" class="v3-input"><option value="hour">{{tr('common.hour')}}</option><option value="day">{{tr('common.day')}}</option><option value="meter">{{tr('common.meter')}}</option><option value="project">{{tr('common.project')}}</option><option value="piece">{{tr('common.piece')}}</option></select></div><label class="block text-sm font-bold">{{tr('app.service_image')}}<input type="file" accept="image/*" class="mt-2 block w-full text-xs" @change="attachServiceImage"/></label><img v-if="serviceImage" :src="serviceImage" class="max-h-32 rounded-xl object-cover"/><button class="v3-primary disabled:opacity-60" :disabled="saving" type="submit">{{tr('app.save_service')}}</button></form></div>
  <div v-if="editingProfile" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/65 p-4" @click.self="editingProfile=false"><form class="card w-full max-w-lg space-y-4 p-6" @submit.prevent="saveProfile"><div class="flex items-center justify-between"><h2 class="text-xl font-extrabold">{{tr('app.my_profile')}}</h2><button type="button" @click="editingProfile=false"><X/></button></div><label class="block text-sm font-bold">{{tr('app.1_name')}}<input v-model="profileName" required minlength="2" maxlength="80" class="v3-input mt-1" :placeholder="tr('app.full_name')"/></label><label class="block text-sm font-bold">{{tr('app.2_profession')}}<select v-model="profileCategoryId" class="v3-input mt-1"><option value="">{{tr('app.choose')}}</option><optgroup v-for="root in catalogCategories" :key="root.id" :label="nameOf(root)"><option v-for="c in (root.children?.length?root.children:[root])" :key="c.id" :value="c.id">{{nameOf(c)}}</option></optgroup></select></label><label class="block text-sm font-bold">{{tr('app.3_city')}}<select v-model="profileCityId" class="v3-input mt-1"><option value="">{{tr('app.choose_2')}}</option><optgroup v-for="p in provinces" :key="p.id" :label="nameOf(p)"><option v-for="c in p.cities" :key="c.id" :value="c.id">{{nameOf(c)}}</option></optgroup></select></label><textarea v-model="profileBio" rows="3" maxlength="1000" :placeholder="tr('app.short_intro_optional')" class="v3-input"></textarea><button type="submit" :disabled="saving" class="v3-primary disabled:opacity-60">{{tr('app.save')}}</button></form></div>
  <div v-if="portfolioModal" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/65 p-4" @click.self="portfolioModal=false"><form class="card w-full max-w-lg space-y-4 p-6" @submit.prevent="addPortfolio"><div class="flex items-center justify-between"><h2 class="text-xl font-extrabold">{{tr('app.add_work_title')}}</h2><button type="button" @click="portfolioModal=false"><X/></button></div><input v-model="newPortfolioTitle" required :placeholder="tr('app.project_title_placeholder')" class="v3-input"/><textarea v-model="newPortfolioDescription" rows="3" :placeholder="tr('app.work_desc_placeholder')" class="v3-input"></textarea><label class="mt-3 flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-blue-400 bg-blue-500/10 p-4 text-sm font-bold text-blue-500 hover:bg-blue-500/20"><ImagePlus :size="19"/> {{tr('app.add_photos_up_to_10')}}<input type="file" multiple accept="image/jpeg,image/png,image/webp" class="sr-only" @change="pickPortfolioImages"/></label><div class="mt-2 grid grid-cols-3 gap-2"><div v-for="(a,i) in portfolioPicked" :key="a.url" class="rounded-xl border p-1 dark:border-slate-700"><img :src="a.url" class="h-20 w-full rounded-lg object-cover" alt=""/><button type="button" class="text-xs text-rose-500" @click="portfolioPicked.splice(i,1)">✕</button></div></div><button type="submit" class="v3-primary">{{tr('app.add_work')}}</button></form></div>
  <div v-if="selectedPerson!==null" class="fixed inset-0 z-[90] overflow-y-auto bg-slate-950/70 p-3 sm:p-8" @click.self="selectedPerson=null"><article class="card mx-auto my-4 max-w-2xl overflow-hidden"><div class="public-profile-cover relative bg-gradient-to-l from-blue-700 to-cyan-500"><img v-if="identityPhotos['person:'+selectedPerson]?.cover" :src="identityPhotos['person:'+selectedPerson]?.cover" class="h-full w-full object-cover"/><button class="absolute end-3 top-3 rounded-full bg-black/60 p-2 text-white" :aria-label="tr('app.close_profile')" @click="selectedPerson=null"><X :size="20"/></button></div><div class="public-profile-body space-y-5 p-5 sm:p-7"><div class="flex items-end gap-4"><div class="public-profile-avatar grid shrink-0 place-items-center overflow-hidden rounded-2xl border-4 border-white bg-indigo-600 text-xl font-bold text-white dark:border-slate-900"><img v-if="identityPhotos['person:'+selectedPerson]?.avatar" :src="identityPhotos['person:'+selectedPerson]?.avatar" class="h-full w-full object-cover"/><span v-else>{{person(selectedPerson).initials}}</span></div><div class="min-w-0 pb-1"><h2 class="text-xl font-extrabold sm:text-2xl">{{person(selectedPerson).name}}</h2><p class="soft text-sm">{{person(selectedPerson).role}} · {{person(selectedPerson).city}}</p></div></div><div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm dark:border-slate-700 dark:bg-slate-800"><div class="font-bold">🛡️ {{tr('app.identity_trust')}}</div><p class="soft mt-1 text-xs leading-6"><template v-if="publicVerified"><span class="font-bold text-emerald-600">✔</span> {{tr('app.this_identity_has_been_verified')}}</template><template v-else>{{tr('app.this_identity_has_not_been_verified')}}</template> {{tr('app.ratings_do_not_guarantee_quality_or')}}</p></div><div class="grid grid-cols-2 gap-2"><a class="flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-3 py-4 text-base font-extrabold text-white" :href="'tel:'+personPhone(selectedPerson)">📞 {{tr('app.call')}}</a><a class="flex items-center justify-center gap-2 rounded-2xl bg-teal-600 px-3 py-4 text-base font-extrabold text-white" :href="waLink(personPhone(selectedPerson))" target="_blank" rel="noopener">💬 {{tr('app.whatsapp')}}</a></div><div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap"><button class="rounded-xl bg-blue-600 px-3 py-3 text-sm font-bold text-white" @click="openServiceRequest('person:'+selectedPerson,person(selectedPerson).name)">{{tr('app.request_services')}}</button><button class="rounded-xl border border-blue-300 px-3 py-3 text-sm font-bold text-blue-600" @click="openChat('person:'+selectedPerson,person(selectedPerson).name,person(selectedPerson).role)">💬 {{tr('app.message')}}</button><button class="rounded-xl border border-rose-300 px-3 py-3 text-sm text-rose-600" @click="toggleFavorite('person:'+selectedPerson)">{{favorites.includes('person:'+selectedPerson)?'♥':'♡'}} {{tr('app.save_2')}}</button><button class="rounded-xl border border-slate-300 px-3 py-3 text-sm dark:border-slate-700" @click="sharePublicProfile('person',selectedPerson)"><Share2 :size="16" class="inline"/> {{tr('app.share')}}</button></div><div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3 dark:border-slate-700"><button v-for="section in (['about','services','portfolio'] as const)" :key="section" class="chip" :class="publicProfileSection===section?'chip-on':'chip-off'" @click="publicProfileSection=section">{{section==='about'?tr('app.section_about'):section==='services'?tr('app.section_services'):tr('app.section_work')}}</button></div><div v-if="publicProfileSection==='about'" class="space-y-3"><h3 class="font-extrabold">{{tr('app.about_specialist')}}</h3><p class="soft text-sm leading-8">{{person(selectedPerson).role}} · {{person(selectedPerson).city}}</p><p v-if="bioForPerson(selectedPerson)" class="text-sm leading-7">{{bioForPerson(selectedPerson)}}</p></div><div v-else-if="publicProfileSection==='services'" class="space-y-3"><h3 class="font-extrabold">{{tr('app.services_offered')}}</h3><p v-if="!publicServices.length" class="soft text-sm">{{tr('app.no_services_listed_yet')}}</p><article v-for="service in publicServices" :key="service.id" class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700"><img v-if="service.images?.[0]" :src="service.images[0]" class="h-40 w-full object-cover" loading="lazy" alt=""/><div class="space-y-2 p-4"><h4 class="font-bold">{{service.title}}</h4><p v-if="service.description" class="soft text-sm leading-7">{{service.description}}</p><p class="text-sm font-bold text-blue-600 dark:text-blue-400">{{priceText(service.pricing)}}</p><button class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white" @click="requestPublicService(service)">{{tr('app.request_this_service')}}</button></div></article><button class="rounded-xl bg-blue-600 px-4 py-2 text-sm text-white" @click="openServiceRequest('person:'+selectedPerson,person(selectedPerson).name)">{{tr('app.request_service')}}</button></div><div v-else class="space-y-3"><h3 class="font-extrabold">{{tr('app.published_work')}}</h3><p v-if="!publicPortfolio.length" class="soft text-sm">{{tr('app.no_published_work')}}</p><div class="grid gap-3 sm:grid-cols-2"><article v-for="work in publicPortfolio" :key="work.id" class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700"><img v-if="work.photo" :src="work.photo" class="h-40 w-full cursor-zoom-in object-cover" @click="portfolioPreview=work.photo"/><div class="p-3"><b>{{work.title}}</b><p class="soft mt-2 line-clamp-3 text-sm">{{work.text}}</p></div></article></div></div></div></article></div>
  <div v-if="selectedBusinessProfile!==null" class="fixed inset-0 z-[90] overflow-y-auto bg-slate-950/70 p-3 sm:p-8" @click.self="selectedBusinessProfile=null"><article v-if="publicBusiness" class="card mx-auto my-4 max-w-2xl overflow-hidden"><div class="public-profile-cover relative bg-gradient-to-l from-indigo-700 to-cyan-500"><img v-if="identityPhotos[String(selectedBusinessProfile)]?.cover" :src="identityPhotos[String(selectedBusinessProfile)]?.cover" class="h-full w-full object-cover"/><button class="absolute end-3 top-3 rounded-full bg-black/50 p-2 text-white" @click="selectedBusinessProfile=null"><X :size="20"/></button></div><div class="public-profile-body space-y-4 p-6"><div class="public-profile-avatar grid shrink-0 place-items-center overflow-hidden rounded-2xl border-4 border-white bg-blue-600 text-xl font-bold text-white dark:border-slate-900"><img v-if="identityPhotos[String(selectedBusinessProfile)]?.avatar" :src="identityPhotos[String(selectedBusinessProfile)]?.avatar" class="h-full w-full object-cover"/><span v-else>{{publicBusiness?.initials}}</span></div><h2 class="text-2xl font-extrabold">{{publicBusiness?.name}}</h2><p class="soft">{{publicBusiness?.category}} · {{publicBusiness?.city}}</p><p class="leading-8">{{publicBusiness?.about}}</p><div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm dark:border-slate-700 dark:bg-slate-800"><div class="font-bold">🛡️ {{tr('app.identity_trust')}}</div><p class="soft mt-1 text-xs leading-6"><template v-if="publicVerified"><span class="font-bold text-emerald-600">✔</span> {{tr('app.this_identity_has_been_verified')}}</template><template v-else>{{tr('app.this_identity_has_not_been_verified')}}</template> {{tr('app.ratings_do_not_guarantee_quality_or')}}</p></div><div class="flex flex-wrap gap-2"><button class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white" @click="openChat('business:'+selectedBusinessProfile,publicBusiness?.name)">💬 {{tr('app.message')}}</button><button class="rounded-xl border border-blue-300 px-4 py-2 text-sm font-bold text-blue-600" @click="openServiceRequest('business:'+selectedBusinessProfile,publicBusiness?.name||tr('app.company_fallback'))">{{tr('app.request_services')}}</button><button class="rounded-xl border border-rose-300 px-4 py-2 text-sm text-rose-600" @click="toggleFavorite('business:'+selectedBusinessProfile)">{{favorites.includes('business:'+selectedBusinessProfile)?'♥':'♡'}} {{tr('app.save_2')}}</button><button class="rounded-xl border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" @click="sharePublicProfile('business',selectedBusinessProfile)">{{tr('app.share')}}</button></div><h3 class="font-extrabold">{{tr('app.company_services')}}</h3><p v-if="!publicServices.length" class="soft text-sm">{{tr('app.no_services_listed_yet_2')}}</p><article v-for="service in publicServices" :key="service.id" class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700"><img v-if="service.images?.[0]" :src="service.images[0]" class="h-40 w-full object-cover" loading="lazy" alt=""/><div class="space-y-2 p-4"><h4 class="font-bold">{{service.title}}</h4><p v-if="service.description" class="soft text-sm leading-7">{{service.description}}</p><p class="text-sm font-bold text-blue-600 dark:text-blue-400">{{priceText(service.pricing)}}</p><button class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white" @click="requestPublicService(service)">{{tr('app.request_this_service')}}</button></div></article></div></article></div>
  <RequestOrderModal v-if="requestOrder" :provider-actor-id="requestOrder.providerActorId" :service-id="requestOrder.serviceId" :name="requestOrder.name" :title="requestOrder.title" @close="requestOrder=null" @created="onOrderCreated"/>
  <ReviewsModal v-if="reviewsActor" :actor-id="reviewsActor.id" :name="reviewsActor.name" @close="reviewsActor=null" @login="reviewsActor=null;openAuth('login')"/>
  <div v-if="toast" role="status" class="fixed bottom-24 left-1/2 z-50 -translate-x-1/2 rounded-xl bg-slate-900 px-5 py-3 text-sm text-white shadow-xl">{{toast}}</div>
</div>
<div v-if="authScreen" class="fixed inset-0 z-[210] overflow-y-auto bg-slate-950/75 p-4" @click.self="authScreen=null"><form class="card mx-auto my-12 max-w-md space-y-4 p-6" @submit.prevent="submitAuth"><div class="flex items-center justify-between"><h2 class="text-xl font-black">{{authScreen==='login'?tr('app.log_in_to_kariva'):tr('app.create_account')}}</h2><button type="button" @click="authScreen=null"><X/></button></div><p class="soft text-xs">{{tr('app.a_verification_code_is_sent_to')}}</p><template v-if="authStep===1"><label v-if="authScreen==='register'" class="block text-sm font-bold">{{tr('app.full_name')}}<input v-model="authName" class="v3-input mt-2" required autocomplete="name"/></label><label class="block text-sm font-bold">{{tr('app.phone_number')}}<input v-model="authPhone" class="v3-input mt-2" type="tel" inputmode="tel" required autocomplete="tel" dir="ltr" placeholder="+93 77 123 4567"/></label></template><template v-else><p class="text-sm" dir="ltr">{{authPhone}}</p><label class="block text-sm font-bold">{{tr('app.verification_code')}}<input v-model="authCode" class="v3-input mt-2" inputmode="numeric" maxlength="6" required autocomplete="one-time-code" dir="ltr" placeholder="• • • • • •"/></label><button type="button" class="text-sm text-blue-600" @click="authStep=1">{{tr('app.change_number')}}</button></template><button :disabled="authBusy" class="w-full rounded-xl bg-blue-600 p-3 font-bold text-white disabled:opacity-60">{{authStep===1?tr('app.get_code'):tr('common.confirm')}}</button><div class="flex flex-wrap justify-center gap-3 text-sm text-blue-600"><button type="button" @click="openAuth('login')">{{tr('common.log_in')}}</button><button type="button" @click="openAuth('register')">{{tr('app.sign_up')}}</button></div></form></div>
</template>
