import { useId } from 'react'
import Select from 'react-select'
import { useI18n } from '@/i18n'

export interface Option {
  value: string
  label: string
}

/**
 * قائمة اختيار بالبحث (react-select) — بديل Select2.
 *
 * القائمة العادية تكفي لخمسة خيارات، وتنهار عند أربعين: اختيار الصنف من
 * اثنين وأربعين صنفاً، أو المستودع من قائمة تطول مع كل فرعٍ جديد. وهنا
 * يُكتب أوّل حرفٍ فتتقلّص القائمة.
 *
 * وهيئتها مبنيّة بـunstyled + أصناف Tailwind لا بأنماط المكتبة: ألوان
 * المنصة متغيّرات CSS تنقلب مع الوضع الليلي، وأنماطها الافتراضية بيضاء
 * ثابتة لا تعرف الليل.
 */
export function SearchSelectControl({
  name,
  options,
  defaultValue,
  placeholder,
  required = false,
  isClearable = false,
  onChange,
  id,
}: {
  name: string
  options: Option[]
  defaultValue?: string
  placeholder?: string
  required?: boolean
  isClearable?: boolean
  onChange?: (value: string) => void
  id?: string
}) {
  const { t, dir } = useI18n()
  const instanceId = useId()

  const selected = options.find((option) => option.value === defaultValue) ?? null

  return (
    <Select<Option>
      name={name}
      inputId={id}
      instanceId={instanceId}
      options={options}
      defaultValue={selected}
      placeholder={placeholder ?? t.common.search}
      noOptionsMessage={() => t.common.empty}
      isRtl={dir === 'rtl'}
      isClearable={isClearable}
      required={required}
      onChange={(option) => onChange?.(option?.value ?? '')}
      unstyled
      /* القائمة تُرسم على الجسم حتى لا يقصّها تمرير نافذة أو جدول */
      menuPortalTarget={typeof document === 'undefined' ? null : document.body}
      styles={{ menuPortal: (base) => ({ ...base, zIndex: 80 }) }}
      classNames={{
        control: ({ isFocused }) =>
          [
            'w-full rounded-lg border bg-[var(--surface-raised)] px-3 py-1.5 text-sm transition',
            isFocused ? 'border-brand-500 ring-2 ring-brand-500/20' : 'border-[var(--border)]',
          ].join(' '),
        valueContainer: () => 'gap-1',
        placeholder: () => 'muted',
        input: () => 'text-[var(--text)]',
        singleValue: () => 'text-[var(--text)]',
        indicatorSeparator: () => 'hidden',
        dropdownIndicator: () => 'muted px-1',
        clearIndicator: () => 'muted px-1 hover:text-danger',
        menu: () =>
          'mt-1 overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--surface-raised)] shadow-lg z-30',
        menuList: () => 'max-h-60 p-1',
        option: ({ isSelected, isFocused }) =>
          [
            'cursor-pointer rounded-lg px-3 py-2 text-sm transition',
            isSelected
              ? 'bg-[var(--accent-soft)] text-accent font-medium'
              : isFocused
                ? 'bg-[var(--surface)]'
                : '',
          ].join(' '),
        noOptionsMessage: () => 'muted p-3 text-center text-sm',
        groupHeading: () => 'muted px-3 pb-1 pt-2 text-[11px] font-semibold uppercase',
      }}
    />
  )
}
