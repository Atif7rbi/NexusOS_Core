export type SaudiMobileErrorReason = "required" | "invalid_type" | "invalid_format";
export class SaudiMobileError extends Error { constructor(public readonly reason: SaudiMobileErrorReason) { super(reason); this.name="SaudiMobileError"; } }
const edge=/^[\u0009-\u000D\u0020]+|[\u0009-\u000D\u0020]+$/g;
const canonical=/^05[0-9]{8}$/;
const map:Record<string,string>={"٠":"0","١":"1","٢":"2","٣":"3","٤":"4","٥":"5","٦":"6","٧":"7","٨":"8","٩":"9","۰":"0","۱":"1","۲":"2","۳":"3","۴":"4","۵":"5","۶":"6","۷":"7","۸":"8","۹":"9"};
function preprocess(value:unknown):string { if(typeof value!=="string") throw new SaudiMobileError("invalid_type"); return value.replace(edge,"").replace(/[٠-٩۰-۹]/g,d=>map[d]); }
export function normalizeRequiredSaudiMobile(value:unknown):string { if(value==null) throw new SaudiMobileError("required"); const n=preprocess(value); if(n==="") throw new SaudiMobileError("required"); if(!canonical.test(n)) throw new SaudiMobileError("invalid_format"); return n; }
export function normalizeNullableSaudiMobile(value:unknown):string|null { if(value==null)return null; const n=preprocess(value); if(n==="")return null; if(!canonical.test(n))throw new SaudiMobileError("invalid_format"); return n; }
export function isInternationalSaudiMobile(value:string):boolean { return /^(?:\+966|00966|966)/.test(value.replace(edge,"")); }
