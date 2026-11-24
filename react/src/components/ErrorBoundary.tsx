import { Component, type ErrorInfo, type ReactNode } from 'react'

/**
 * حاجز الأخطاء.
 *
 * رميةٌ واحدة غير ملتقَطة في أيّ مكوّن تُفرغ الشجرة كلّها: صفحةٌ بيضاء
 * بلا كلمة، في كل المسارات لا في المسار الذي أخطأ. وقد وقع ذلك فعلاً
 * حين رمى حقل الهاتف قبل وصول أدوات تحقّقه.
 *
 * فالحاجز هنا يحصر العطب في الصفحة: يقول إنّ خطأً وقع، ويعرض طريق
 * العودة — والتفصيل في وحدة التحكّم للمطوّر لا على الشاشة للمستخدم.
 */
interface State {
  failed: boolean
}

export class ErrorBoundary extends Component<{ children: ReactNode; fallback: ReactNode }, State> {
  state: State = { failed: false }

  static getDerivedStateFromError(): State {
    return { failed: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('[boundary]', error, info.componentStack)
  }

  render(): ReactNode {
    return this.state.failed ? this.props.fallback : this.props.children
  }
}
