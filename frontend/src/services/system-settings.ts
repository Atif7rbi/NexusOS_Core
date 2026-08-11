import {requestJson} from "@/lib/http";
import type {CompanyProfileInput,SystemSettings,SystemSettingsResponse} from "@/types/system-settings";
export async function fetchSystemSettings(token:string):Promise<SystemSettings>{return (await requestJson<SystemSettingsResponse>("/system-settings",{token})).data;}
export async function updateCompanyProfile(token:string,profile:CompanyProfileInput):Promise<SystemSettings>{return (await requestJson<SystemSettingsResponse>("/system-settings",{token,method:"PUT",body:profile})).data;}
