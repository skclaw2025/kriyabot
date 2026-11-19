export type FinanceType = "income" | "expense";
export type Head = { id: number; name: string; type: FinanceType; color?: string | null; entries_count?: number };
export type Entry = {
  id: number;
  type: FinanceType;
  amount: string | number;
  tax_amount?: string | number | null;
  currency?: string;
  entry_date: string;
  party?: string | null;
  bill_no?: string | null;
  description?: string | null;
  payment_method?: string | null;
  source: "manual" | "scan" | "assistant";
  needs_review?: boolean;
  ai_suggested_head?: string | null;
  ai_confidence?: number | null;
  has_receipt?: boolean;
  receipt_original_name?: string | null;
  receipt_mime?: string | null;
  head_id?: number | null;
  head?: { id: number; name: string; type: FinanceType; color?: string | null } | null;
};
export type Summary = {
  from: string; to: string; income: number; expense: number; balance: number; entries: number; needs_review: number;
  by_head: Array<{ type: FinanceType; head_id: number | null; name: string; total: number; entries: number }>;
  today: { date: string; income: number; expense: number; entries: number };
};
export const PAYMENT_METHODS = [
  { value: "upi", label: "UPI" }, { value: "cash", label: "Cash" }, { value: "card", label: "Card" },
  { value: "bank", label: "Bank transfer" }, { value: "cheque", label: "Cheque" }, { value: "other", label: "Other" },
];
export const paymentLabel = (value?: string | null) => PAYMENT_METHODS.find(p => p.value === value)?.label ?? "";
