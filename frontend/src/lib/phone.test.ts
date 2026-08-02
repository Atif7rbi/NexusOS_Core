import {describe,expect,it} from "vitest";
import {normalizeNullableSaudiMobile,normalizeRequiredSaudiMobile,SaudiMobileError} from "./phone";
describe("Saudi mobile",()=>{
  it.each([["0501234567","0501234567"],["\t٠٥٠١٢٣٤٥٦٧ ","0501234567"],["۰۵۰۱۲۳۴۵۶۷","0501234567"]])("normalizes",(i,o)=>{expect(normalizeRequiredSaudiMobile(i)).toBe(o);expect(normalizeNullableSaudiMobile(i)).toBe(o);});
  it("handles nullable",()=>{expect(normalizeNullableSaudiMobile(" \t\n")).toBeNull();expect(()=>normalizeRequiredSaudiMobile(" \t\n")).toThrowError(new SaudiMobileError("required"));});
  it.each(["\u00a00501234567","\u00000501234567","050 123 4567","+966501234567","966501234567","00966501234567","051234567"])("rejects",i=>expect(()=>normalizeRequiredSaudiMobile(i)).toThrowError(new SaudiMobileError("invalid_format")));
});
