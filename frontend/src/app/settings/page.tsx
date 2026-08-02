"use client";
import {Phone,Save,Settings} from "lucide-react";
import {useCallback,useEffect,useMemo,useState} from "react";
import {AppShell} from "@/components/layout/AppShell";
import {Button} from "@/components/ui/Button";
import {Input} from "@/components/ui/Input";
import {useTranslation} from "@/hooks/useTranslation";
import {isInternationalSaudiMobile,normalizeNullableSaudiMobile} from "@/lib/phone";
import {useAuth} from "@/providers/AuthProvider";
import {fetchSystemSettings,updateSystemSettingsPhone} from "@/services/system-settings";
export default function SettingsPage(){
 const{token}=useAuth();const{isArabic}=useTranslation();const[phone,setPhone]=useState("");const[loading,setLoading]=useState(true);const[saving,setSaving]=useState(false);const[error,setError]=useState<string|null>(null);const[success,setSuccess]=useState<string|null>(null);
 const l=useMemo(()=>isArabic?{title:"الإعدادات",desc:"إدارة رقم التواصل العام للنظام.",card:"رقم التواصل",phone:"رقم الجوال",save:"حفظ رقم التواصل",saved:"تم تحديث رقم التواصل بنجاح.",load:"تعذر تحميل رقم التواصل.",fail:"تعذر حفظ رقم التواصل.",format:"استخدم رقم جوال محليًا من 10 أرقام يبدأ بـ 05.",intl:"استخدم الصيغة المحلية 05 بدل صيغة رمز الدولة."}:{title:"Settings",desc:"Manage the system contact phone number.",card:"Contact phone",phone:"Mobile number",save:"Save contact phone",saved:"Contact phone updated successfully.",load:"Unable to load the contact phone.",fail:"Unable to save the contact phone.",format:"Use a 10-digit local mobile number starting with 05.",intl:"Use the local 05 format instead of a country-code format."},[isArabic]);
 const load=useCallback(async()=>{if(!token)return;setLoading(true);setError(null);try{setPhone((await fetchSystemSettings(token)).phone??"");}catch{setError(l.load);}finally{setLoading(false);}},[l.load,token]);
 useEffect(()=>{const id=window.setTimeout(()=>void load(),0);return()=>window.clearTimeout(id);},[load]);
 const submit=async()=>{if(!token)return;setError(null);setSuccess(null);let value:string|null;try{value=normalizeNullableSaudiMobile(phone);}catch{setError(isInternationalSaudiMobile(phone)?l.intl:l.format);return;}setSaving(true);try{setPhone((await updateSystemSettingsPhone(token,value)).phone??"");setSuccess(l.saved);}catch{setError(l.fail);}finally{setSaving(false);}};
 return <AppShell><main className="space-y-6 p-4 sm:p-6 lg:p-8"><header className="flex items-start gap-4"><span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)]"><Settings size={22}/></span><div><h1 className="text-2xl font-bold">{l.title}</h1><p className="mt-1 text-sm text-[var(--text-secondary)]">{l.desc}</p></div></header><section className="max-w-2xl rounded-3xl border border-[var(--border)] bg-[var(--surface)] p-6"><h2 className="text-lg font-bold">{l.card}</h2>{loading?<div className="mt-6 h-12 animate-pulse rounded-xl bg-[var(--surface-muted)]" aria-label="loading"/>:<div className="mt-6 space-y-5"><Input label={l.phone} name="settings_phone" value={phone} onChange={e=>{setPhone(e.target.value);setError(null);setSuccess(null);}} onBlur={()=>{try{setPhone(normalizeNullableSaudiMobile(phone)??"");}catch{/* Keep invalid input. */}}} inputMode="tel" placeholder="05xxxxxxxx" leading={<Phone size={17}/>} error={error}/>{success?<p role="status" className="text-sm font-semibold text-[var(--success)]">{success}</p>:null}<Button type="button" onClick={()=>void submit()} isLoading={saving} leadingIcon={<Save size={17}/>}>{l.save}</Button></div>}</section></main></AppShell>;
}
