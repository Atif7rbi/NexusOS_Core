export type SystemSettings = {
  id: number;
  company_name_ar: string;
  company_name_en: string | null;
  short_name_ar: string;
  short_name_en: string | null;
  company_tagline_ar: string | null;
  company_tagline_en: string | null;
  logo_path: string | null;
  favicon_path: string | null;
  primary_color: string;
  secondary_color: string;
  language: string;
  timezone: string;
  currency: string;
  date_format: string;
  phone: string | null;
  email: string | null;
  website: string | null;
  address: string | null;
  commercial_registration: string | null;
  vat_number: string | null;
  created_at: string;
  updated_at: string;
};

export type SystemSettingsResponse = {
  data: SystemSettings;
};

export type CompanyProfileInput = Pick<
  SystemSettings,
  | "company_name_ar"
  | "company_name_en"
  | "short_name_ar"
  | "short_name_en"
  | "phone"
  | "email"
  | "address"
  | "website"
  | "commercial_registration"
  | "vat_number"
>;
