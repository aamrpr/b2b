/**
 * العربية تميّز ستّ حالات للعدد لا اثنتين: «صنف فرعي واحد»، «صنفان
 * فرعيان»، «6 أصناف فرعية»، «11 صنفاً فرعياً». الإنجليزية تكتفي
 * بحالتين، فالحقول الزائدة اختيارية ويعود `Intl.PluralRules` بما
 * تحتاجه كل لغة.
 */
export interface PluralForms {
  zero?: string
  one: string
  two?: string
  few?: string
  many?: string
  other: string
}
