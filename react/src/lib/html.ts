/**
 * يمنع نصّ المستخدم من أن يصير وسماً في خليّة جدول.
 *
 * DataTables يرسم الخلايا سلاسلَ HTML لا مكوّنات، فما يكتبه مستوردٌ في
 * اسم منتجه يصل المتصفّح وسماً إن لم يُهرَّب. وReact كان يهرّبه عنّا؛
 * هنا نفعلها بأيدينا.
 *
 * وهي في ملفٍّ مستقلّ لا في مكوّن الجدول عن قصد: الصفحات تستوردها
 * لتبني خلاياها، ولو خرجت من ملفّ المكوّن لجرّت jQuery وDataTables معها
 * إلى الحزمة الأولى وأبطلت تأجيلهما.
 */
export function esc(value: unknown): string {
  if (value === null || value === undefined) return ''

  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}
