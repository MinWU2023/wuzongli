export type DialJob = {
  id: number;
  company_id?: number | null;
  task_id?: number | null;
  phone: string;
  company_name?: string | null;
  city?: string | null;
  domain?: string | null;
  email?: string | null;
  address?: string | null;
  source_url?: string | null;
  status: string;
  claimed_by?: string | null;
  claimed_at?: string | null;
  completed_at?: string | null;
  note?: string | null;
  created_at?: string | null;
};

export type Company = {
  id: number;
  task_id: number;
  company_name?: string | null;
  domain?: string | null;
  city?: string | null;
  country?: string | null;
  phone?: string | null;
  phone_alt?: string | null;
  email?: string | null;
  whatsapp?: string | null;
  address?: string | null;
  source_url?: string | null;
  linkedin?: string | null;
  created_at?: string | null;
};

export type PipelineTask = {
  id: number;
  city: string;
  target_market?: string;
  stage?: string;
  status?: string;
  progress?: number;
  keyword_count?: number;
  pages_target?: number;
  error_message?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type AiRelayInfo = {
  id: number;
  name?: string;
  base_url?: string;
  model?: string;
  masked_key?: string;
  has_key?: boolean;
};

export type ServerSettings = {
  search_provider?: string;
  serper_configured?: boolean;
  serper_masked?: string;
  ai_relay?: AiRelayInfo | null;
  saved?: boolean;
};

export type AppConfig = {
  apiBase: string;
  apiKey: string;
  deviceId: string;
  acceptOrders: boolean;
  aiBaseUrl?: string;
  aiModel?: string;
};
