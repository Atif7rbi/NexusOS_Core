import {requestJson} from "@/lib/http";
import type {SystemSettings,SystemSettingsResponse} from "@/types/system-settings";
export async function fetchSystemSettings(token:string):Promise<SystemSettings>{return (await requestJson<SystemSettingsResponse>("/system-settings",{token})).data;}
export async function updateSystemSettingsPhone(token:string,phone:string|null):Promise<SystemSettings>{return (await requestJson<SystemSettingsResponse>("/system-settings",{token,method:"PUT",body:{phone}})).data;}
