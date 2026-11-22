export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface TokenUsage {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens?: number;
}

export interface Generation {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_charge_min: string;
    estimated_charge_max: string;
    charge: string | null;
    usage: TokenUsage | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    waiting_reason: string | null;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
}

export interface Estimate {
    charge_min: string;
    charge_max: string;
    tokens_min: TokenUsage;
    tokens_max: TokenUsage;
}

export interface Paginated<T> {
    data: T[];
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null };
}

export const IN_FLIGHT: GenerationStatus[] = ['pending', 'queued', 'processing'];
