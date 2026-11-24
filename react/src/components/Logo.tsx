import { useTheme } from '@/store/theme'
import { useI18n } from '@/i18n'

/**
 * شعار المنصة.
 * الكحلي في الشعار لا يُقرأ على خلفية الوضع الليلي، فتُستخدم له نسخة
 * فاتحة، والعلامة وحدها على الشاشات الضيّقة.
 */
export function Logo({ variant = 'full', className }: { variant?: 'full' | 'mark'; className?: string }) {
  const { t } = useI18n()
  const isDark = useTheme((s) => s.resolved()) === 'dark'

  if (variant === 'mark') {
    // كحليّ العلامة لا يُقرأ على خلفية ليلية كما الشعار الكامل
    return <img src={isDark ? '/brand/mark-dark.png' : '/brand/mark.png'} alt={t.app.name} className={className} />
  }

  return (
    <img
      src={isDark ? '/brand/logo-dark.png' : '/brand/logo.png'}
      alt={t.app.name}
      className={className}
      // الأبعاد معلومة مسبقاً فلا يقفز التخطيط أثناء التحميل
      width={640}
      height={187}
    />
  )
}
