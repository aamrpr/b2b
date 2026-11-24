/**
 * كاتبا CSV و xlsx.
 *
 * وهما هنا لا في إضافة Buttons من DataTables عن قصد: تلك تُصدّر ما في
 * DOM، وجداولنا تُعالَج في الخادم فليس في DOM إلّا الصفحة المعروضة —
 * فيخرج ملفٌّ فيه خمسة وعشرون سطراً ويظنّ صاحبه أنّه أخذ الجدول كلّه.
 * فالتصدير هنا يطلب من الخادم ما طابق البحث كلّه ثمّ يكتبه بنفسه.
 */
import JSZip from 'jszip'

/** يهرب الحقل كما يقتضي RFC 4180: تُضاعَف علامة الاقتباس ويُحاط الحقل بها. */
function csvField(value: string): string {
  return /[",\n\r]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
}

/**
 * CSV بعلامة ترتيب البايتات.
 *
 * بدونها يفتح Excel الملفّ بترميز النظام فيصير العربيّ رموزاً — وهي
 * ثلاثة بايتات تفصل بين ملفٍّ يُقرأ وملفٍّ يُرمى.
 */
export function toCsv(headers: string[], rows: string[][]): Blob {
  const lines = [headers, ...rows].map((row) => row.map(csvField).join(',')).join('\r\n')

  return new Blob(['﻿' + lines], { type: 'text/csv;charset=utf-8' })
}

function xmlText(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    // المحارف الضابطة غير مسموحة في XML 1.0، وتأتي أحياناً في ملاحظةٍ ملصوقة
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
}

/** حرف العمود في Excel: 1 ⟵ A، و27 ⟵ AA. */
function columnName(index: number): string {
  let name = ''
  let n = index

  while (n > 0) {
    const rest = (n - 1) % 26
    name = String.fromCharCode(65 + rest) + name
    n = Math.floor((n - rest) / 26)
  }

  return name
}

function sheetXml(headers: string[], rows: string[][]): string {
  const cell = (value: string, column: number, row: number) =>
    `<c r="${columnName(column)}${row}" t="inlineStr"><is><t xml:space="preserve">${xmlText(value)}</t></is></c>`

  const body = [headers, ...rows]
    .map(
      (cells, index) =>
        `<row r="${index + 1}">${cells.map((value, column) => cell(value, column + 1, index + 1)).join('')}</row>`,
    )
    .join('')

  return (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
    '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
    `<sheetData>${body}</sheetData>` +
    '</worksheet>'
  )
}

/**
 * مصنّف xlsx بورقةٍ واحدة.
 *
 * وكلّ خليّة نصٌّ ضمنيّ (`inlineStr`): لا جدول سلاسل مشتركة، ولا تحويل
 * أرقام. والمبالغ هنا منسَّقة أصلاً بفواصلها وعملتها — ولو تُركت أرقاماً
 * لأعاد Excel تنسيقها بإعداداته هو، فاختلف ما يُقرأ عمّا على الشاشة.
 */
export async function toXlsx(headers: string[], rows: string[][], sheetName = 'Sheet1'): Promise<Blob> {
  const zip = new JSZip()

  zip.file(
    '[Content_Types].xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
      '<Default Extension="xml" ContentType="application/xml"/>' +
      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
      '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' +
      '</Types>',
  )

  zip.folder('_rels')!.file(
    '.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
      '</Relationships>',
  )

  const xl = zip.folder('xl')!

  xl.file(
    'workbook.xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' +
      'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
      `<sheets><sheet name="${xmlText(sheetName).slice(0, 31)}" sheetId="1" r:id="rId1"/></sheets>` +
      '</workbook>',
  )

  xl.folder('_rels')!.file(
    'workbook.xml.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' +
      '</Relationships>',
  )

  xl.folder('worksheets')!.file('sheet1.xml', sheetXml(headers, rows))

  return zip.generateAsync({
    type: 'blob',
    mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  })
}

/** يحفظ الملفّ باسمه. */
export function download(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()

  // الإفراج بعد النقرة لا قبلها: المتصفّح يقرأ العنوان بعد انتهاء المعالج
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
