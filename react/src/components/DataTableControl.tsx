import { useEffect, useRef } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import DataTablesCore, { type Api, type Config, type ConfigColumns } from 'datatables.net-dt'
import 'datatables.net-responsive-dt'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { esc } from '@/lib/html'
import { toast } from '@/store/toast'
import { DateRangeFilter } from '@/components/fields/DateField'

/**
 * غلاف DataTables.
 *
 * المكتبة تملك الجدول: تبني ترويسته وجسمه وتُدير فرزه وبحثه وتصفّحه
 * في DOM. وReact هنا لا يرسم داخله شيئاً — يعطيها عنصر <table> فارغاً
 * ثمّ لا يمسّه، لأنّ شجرتين تتنازعان DOM واحداً هو أسرع طريق إلى
 * جدولٍ يختفي نصفه بعد أوّل إعادة رسم.
 *
 * ولذلك خلايا الإجراءات نصوص HTML لا مكوّنات: الأزرار تُرسَم سلاسلَ،
 * والنقر يُلتقَط بالتفويض من جذر الجدول إلى onAction. وهذا هو الثمن
 * الذي تدفعه مكتبةٌ مبنيّة على jQuery داخل تطبيق React.
 *
 * الاستثناء حقول التاريخ في صفّ التصفية: تقويم المنصة مكوّن React،
 * فيُركَّب في جذرٍ مستقلّ داخل الخلية ويُفكَّ قبل هدم الجدول.
 */

export interface DataTableColumn<T = unknown> {
  /** مفتاح العمود — هو نفسه ما يُرسَل في columns[i][data] ويقابله الخادم */
  data: string | null
  title: string
  /** HTML الخلية. يُعاد نصاً، فأيّ قيمة من المستخدم تمرّ عبر escape */
  render?: (row: T) => string
  orderable?: boolean
  searchable?: boolean
  className?: string
  width?: string
  /** حقل تصفية في صفّ الترويسة الثاني */
  filter?:
    | { type: 'text'; placeholder?: string }
    | { type: 'select'; options: { value: string; label: string }[] }
    | { type: 'dates' }
  /** على الهاتف: 1 يبقى ظاهراً، والأعلى يُطوى أوّلاً */
  priority?: number
  /**
   * قيمة العمود في الملفّ المصدَّر.
   *
   * الافتراضي نصّ `render` منزوعَ الوسوم — وهو ما يُقرأ على الشاشة.
   * و`false` تُخرج العمود كلّه: عمودُ صورٍ أو أزرار لا معنى له في جدول.
   * وأعمدة الواجهة (التي لا `data` لها) تخرج بلا إعلان.
   */
  export?: false | ((row: T) => string)
}

export interface DataTableProps<T = unknown> {
  /** مسار الواجهة البرمجية — مع serverSide */
  url?: string
  /** بيانات محمَّلة — بلا serverSide */
  rows?: T[]
  columns: DataTableColumn<T>[]
  /** الترتيب الابتدائي: مفتاح العمود واتجاهه */
  order?: [string, 'asc' | 'desc']
  pageLength?: number
  paging?: boolean
  searching?: boolean
  info?: boolean
  /** معاملات ثابتة تُضاف إلى كل نداء */
  params?: Record<string, string | number | boolean | undefined>
  /** يتغيّر فيُعاد تحميل الجدول */
  reloadKey?: number | string
  /** مفاتيح الاستجابة الزائدة — العملة، أو is_mediator */
  onResponse?: (payload: Record<string, unknown>) => void
  /**
   * فعلٌ من داخل الجدول: نقرةٌ على زرّ يحمل data-action، أو تبدّل
   * قائمةٍ تحمله. وvalue قيمة القائمة إن كان الفعل تبدّلاً، وrow سطر
   * البيانات كما جاء من الخادم — فما يحتاج السطر كلّه لا يُعيد طلبه.
   */
  onAction?: (action: string, id: string, value: string, row?: T) => void
  emptyMessage?: string
  /** أزرار التصدير. اسم الملفّ بلا امتداد. */
  exportable?: boolean
  exportName?: string
}

export function DataTableControl<T>({
  url,
  rows,
  columns,
  order,
  pageLength = 25,
  paging = true,
  searching = true,
  info = true,
  params,
  reloadKey,
  onResponse,
  onAction,
  emptyMessage,
  exportable = true,
  exportName,
}: DataTableProps<T>) {
  const { t, locale, dir } = useI18n()
  const tableRef = useRef<HTMLTableElement>(null)
  const tableApi = useRef<Api<T> | null>(null)

  /*
   * الخيارات تُقرأ من مرجع لا من الخاصّيات مباشرة: الجدول يُبنى مرّة،
   * وما يتبدّل بعدها (دالّة الإجراء، المعاملات) ينبغي أن يصل إليه بلا
   * هدمه وإعادة بنائه عند كل إعادة رسم.
   */
  const latest = useRef({ params, onAction, onResponse })
  latest.current = { params, onAction, onResponse }

  useEffect(() => {
    const element = tableRef.current
    if (!element) return

    const index = (key: string) => columns.findIndex((column) => column.data === key)

    const config: Config = {
      // يُبنى الجدول كاملاً من هنا: React يعطي العنصر ولا يرسم داخله
      columns: columns.map(
        (column): ConfigColumns => ({
          data: column.data,
          title: column.title,
          orderable: column.orderable ?? column.data !== null,
          searchable: column.searchable ?? column.data !== null,
          className: column.className,
          width: column.width,
          responsivePriority: column.priority ?? 5,
          defaultContent: '',
          render: column.render
            ? (_value: unknown, type: string, row: T) =>
                type === 'display'
                  ? column.render!(row)
                  : ((row as Record<string, unknown>)[column.data ?? ''] as string)
            : undefined,
        }),
      ),
      order: order && index(order[0]) >= 0 ? [[index(order[0]), order[1]]] : [],
      pageLength,
      paging,
      searching,
      info,
      lengthMenu: [10, 25, 50, 100],
      /* البحث العام أُزيل من فوق. التصفية تبقى في صفّ الأعمدة */
      layout: {
        topStart: 'pageLength',
        topEnd: null,
        bottomStart: 'info',
        bottomEnd: 'paging',
      },
      /* الأعمدة تبقى ظاهرة. ما زاد عن العرض يُمرَّر من داخل الجدول لا بطيه */
      responsive: false,
      autoWidth: false,
      // صفّ التصفية يسكن ترويسةً ثانية، فيبقى الفرز على الأولى
      orderCellsTop: true,
      processing: true,
      language: {
        search: '',
        searchPlaceholder: t.dt.searchPlaceholder,
        lengthMenu: t.dt.lengthMenu,
        info: t.dt.info,
        infoEmpty: t.dt.infoEmpty,
        infoFiltered: t.dt.infoFiltered,
        emptyTable: emptyMessage ?? t.dt.empty,
        zeroRecords: t.dt.zeroRecords,
        loadingRecords: t.dt.loading,
        processing: t.dt.processing,
        paginate: {
          first: t.dt.first,
          last: t.dt.last,
          next: t.dt.next,
          previous: t.dt.previous,
        },
        aria: {
          sortAscending: t.dt.sortAscending,
          sortDescending: t.dt.sortDescending,
        },
      },
    }

    if (url) {
      config.serverSide = true
      config.processing = true
      /*
       * النداء يمرّ بـaxios لا بـjQuery: الرمز والترويسات ومعالجة ٤٠١
       * كلّها في معترضات api، ولا ينبغي أن يكون للجدول طريقٌ ثانٍ
       * إلى الخادم لا يمرّ بها.
       */
      config.ajax = (data: object, callback: (payload: unknown) => void) => {
        const query = data as Record<string, unknown>

        api
          .get(url, { params: { ...query, ...latest.current.params } })
          .then((response) => {
            latest.current.onResponse?.(response.data as Record<string, unknown>)
            callback(response.data)
          })
          .catch((error) => {
            /*
             * الفشل يُقال لا يُكتَم: جدولٌ فارغ بعد ٤٠٣ يبدو «لا نتائج»،
             * فيظنّ قارئه أنّ البيانات ليست موجودة وهي ممنوعةٌ عنه.
             */
            toast(errorMessage(error, t.dt.failed))

            callback({ draw: query.draw, recordsTotal: 0, recordsFiltered: 0, data: [] })
          })
      }
    } else {
      config.data = rows ?? []
    }

    const table = new DataTablesCore<T>(element, config) as Api<T>
    tableApi.current = table

    /* ---------- التصدير ---------- */

    const exported = columns.filter((column) => column.data !== null && column.export !== false)

    /** نصّ الخليّة كما يُقرأ على الشاشة، بلا وسوم. */
    const cellText = (column: DataTableColumn<T>, row: T): string => {
      if (typeof column.export === 'function') return column.export(row)

      const raw = column.render
        ? column.render(row)
        : String((row as Record<string, unknown>)[column.data ?? ''] ?? '')

      /*
       * الفاصل قبل النزع: الاسم في وسمٍ والصنف تحته في آخر، وtextContent
       * يلصقهما — «سريعةقطع سيارات». فكلّ حدّ كتلةٍ يصير فاصلاً منقوطاً.
       */
      const box = document.createElement('div')
      box.innerHTML = raw.replace(/<\/?(div|p|li|tr)\b[^>]*>|<br\s*\/?>/gi, '\u0001')

      return (box.textContent ?? '')
        .split('\u0001')
        .map((part) => part.replace(/\s+/g, ' ').trim())
        .filter(Boolean)
        .join(' · ')
    }

    /**
     * سطور التصدير.
     *
     * الجدول المحلّي يملك سطوره كلّها، فتُؤخذ منه بترتيبها وتصفيتها
     * الحاليين. والمصفَّح من الخادم لا يملك إلّا صفحته، فيُعاد الطلب
     * بنفس البحث والترتيب و‎export=1‎ — فيكون الملفّ ما طابق لا ما ظهر.
     */
    const exportRows = async (): Promise<{ rows: T[]; truncated: boolean }> => {
      if (!url) {
        return { rows: table.rows({ search: 'applied', order: 'applied' }).data().toArray(), truncated: false }
      }

      const state = table.ajax.params() as Record<string, unknown>
      const response = await api.get<{ data: T[]; truncated?: boolean; recordsFiltered?: number }>(url, {
        params: { ...state, ...latest.current.params, export: 1, length: -1, start: 0 },
      })

      return { rows: response.data.data ?? [], truncated: response.data.truncated === true }
    }

    const runExport = async (format: 'csv' | 'xlsx', button: HTMLButtonElement) => {
      const label = button.textContent ?? ''

      button.disabled = true
      button.textContent = t.dt.exporting

      try {
        const { rows, truncated } = await exportRows()

        if (rows.length === 0) {
          toast(t.dt.exportEmpty)

          return
        }

        const headers = exported.map((column) => column.title)
        const body = rows.map((row) => exported.map((column) => cellText(column, row)))
        const name = `${exportName ?? 'table'}-${new Date().toISOString().slice(0, 10)}`

        const { toCsv, toXlsx, download } = await import('@/lib/sheet')

        format === 'csv'
          ? download(toCsv(headers, body), `${name}.csv`)
          : download(await toXlsx(headers, body, exportName ?? 'Sheet1'), `${name}.xlsx`)

        // الصدق في الحدّ: من أخذ خمسة آلاف سطر ينبغي أن يعرف أنّ وراءها غيرها
        if (truncated) toast(t.dt.exportTruncated.replace(':count', String(rows.length)))
      } catch {
        toast(t.dt.exportFailed)
      } finally {
        button.disabled = false
        button.textContent = label
      }
    }

    if (exportable && exported.length > 0) {
      const bar = document.createElement('div')
      bar.className = 'dt-export'

      ;(['csv', 'xlsx'] as const).forEach((format) => {
        const button = document.createElement('button')

        button.type = 'button'
        button.className = 'dt-btn'
        button.textContent = format === 'csv' ? t.dt.exportCsv : t.dt.exportExcel
        button.addEventListener('click', () => void runExport(format, button))
        bar.appendChild(button)
      })

      element.closest('.dt-card')?.querySelector('.dt-layout-row')?.appendChild(bar)
    }

    /* صفّ التصفية: حقولٌ تحت الترويسة، كلٌّ يبحث في عموده */
    const filters = columns
      .map((column, position) => ({ column, position }))
      .filter((entry) => entry.column.filter)

    const dateRoots: Root[] = []
    let filterTimer: ReturnType<typeof setTimeout> | undefined

    if (filters.length > 0) {
      const head = element.querySelector('thead')
      const row = document.createElement('tr')
      row.className = 'dt-filters'
      const dateMounts: { host: HTMLDivElement; position: number; title: string }[] = []

      columns.forEach((column, position) => {
        const cell = document.createElement('th')
        const spec = column.filter

        if (spec?.type === 'text') {
          const input = document.createElement('input')
          input.type = 'search'
          input.className = 'dt-filter-input'
          input.placeholder = spec.placeholder ?? column.title
          input.setAttribute('aria-label', column.title)
          cell.appendChild(input)
        }

        if (spec?.type === 'dates') {
          const box = document.createElement('div')
          box.className = 'dt-filter-dates'
          cell.appendChild(box)
          dateMounts.push({ host: box, position, title: column.title })
        }

        if (spec?.type === 'select') {
          const select = document.createElement('select')
          select.className = 'dt-filter-input'
          select.setAttribute('aria-label', column.title)
          select.innerHTML =
            `<option value="">${esc(t.dt.filterAll)}</option>` +
            spec.options
              .map((option) => `<option value="${esc(option.value)}">${esc(option.label)}</option>`)
              .join('')
          cell.appendChild(select)
        }

        row.appendChild(cell)
      })

      head?.appendChild(row)

      dateMounts.forEach(({ host, position, title }) => {
        const root = createRoot(host)
        dateRoots.push(root)
        root.render(
          <DateRangeFilter
            locale={locale}
            dir={dir}
            title={title}
            fromLabel={t.dt.from}
            toLabel={t.dt.to}
            clearLabel={t.common.clear}
            onChange={(value) => {
              table.column(position).search(value).draw()
            }}
          />,
        )
      })

      const onFilter = (event: Event) => {
        const field = event.target as HTMLInputElement | HTMLSelectElement
        const isSelect = field.tagName === 'SELECT'

        // القائمة تُطبَّق عند التغيير، والنصّ عند الكتابة — لا الاثنان معاً
        if (isSelect ? event.type !== 'change' : event.type !== 'input') return

        const position = Array.prototype.indexOf.call(row.children, field.parentElement)
        if (position < 0) return

        const apply = () => table.column(position).search(field.value).draw()

        clearTimeout(filterTimer)
        isSelect ? apply() : (filterTimer = setTimeout(apply, 400))
      }

      row.addEventListener('input', onFilter)
      row.addEventListener('change', onFilter)
    }

    /*
     * الإجراءات: تفويضٌ من جذر الجدول، فالأزرار نصوصٌ لا مكوّنات.
     * وما يحمل data-confirm يُسلَّح أوّلاً ويُنفَّذ بالنقرة الثانية، ثمّ
     * يعود إلى حاله بعد أربع ثوانٍ — كزرّ التأكيد في بقيّة المنصة،
     * فالحذف لا ينبغي أن يقع بنقرةٍ واحدة في جدولٍ طويل.
     */
    let disarm: ReturnType<typeof setTimeout> | undefined

    /** سطر البيانات الذي يقع فيه العنصر — تقرؤه المكتبة من فهرس الصفّ */
    const rowOf = (node: HTMLElement): T | undefined => {
      const tr = node.closest('tr')

      return tr ? (table.row(tr).data() as T | undefined) : undefined
    }

    const reset = (button: HTMLElement) => {
      button.textContent = button.dataset.label ?? button.textContent
      delete button.dataset.armed
    }

    const onClick = (event: Event) => {
      const trigger = (event.target as HTMLElement).closest<HTMLElement>('[data-action]')
      if (!trigger || !element.contains(trigger)) return

      event.preventDefault()

      if (trigger.dataset.confirm && !trigger.dataset.armed) {
        element.querySelectorAll<HTMLElement>('[data-armed]').forEach(reset)
        trigger.dataset.label = trigger.textContent ?? ''
        trigger.textContent = trigger.dataset.confirm
        trigger.dataset.armed = 'yes'

        clearTimeout(disarm)
        disarm = setTimeout(() => reset(trigger), 4000)

        return
      }

      clearTimeout(disarm)
      if (trigger.dataset.armed) reset(trigger)

      latest.current.onAction?.(
        trigger.dataset.action ?? '',
        trigger.dataset.id ?? '',
        '',
        rowOf(trigger),
      )
    }

    const onChange = (event: Event) => {
      const field = (event.target as HTMLElement).closest<HTMLSelectElement | HTMLInputElement>(
        '[data-action]',
      )
      if (!field || !element.contains(field) || field.closest('tr.dt-filters')) return

      latest.current.onAction?.(
        field.dataset.action ?? '',
        field.dataset.id ?? '',
        field.value,
        rowOf(field),
      )
    }

    element.addEventListener('click', onClick)
    element.addEventListener('change', onChange)

    return () => {
      clearTimeout(disarm)
      clearTimeout(filterTimer)
      dateRoots.forEach((root) => root.unmount())
      element.removeEventListener('click', onClick)
      element.removeEventListener('change', onChange)
      table.destroy()
      element.innerHTML = ''
      tableApi.current = null
    }
    // الجدول يُبنى مرّة لكل عنوانٍ ولغة؛ وما سواهما يصل عبر latest
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [url, locale])

  /*
   * تغيّر المفتاح أو المعاملات ⟵ إعادة تحميل بلا هدم.
   * والجدول المحلّي لا ajax له، وطلب إعادة تحميله يرمي.
   */
  useEffect(() => {
    if (url) tableApi.current?.ajax.reload(undefined, false)
  }, [url, reloadKey, JSON.stringify(params)])

  /* الوضع المحلّي: بياناتٌ جديدة تُستبدَل في مكانها */
  useEffect(() => {
    if (url || !tableApi.current) return

    tableApi.current.clear().rows.add(rows ?? []).draw(false)
  }, [rows, url])

  return (
    <div className="dt-card">
      <table ref={tableRef} className="dt-table w-full text-sm" />
    </div>
  )
}
