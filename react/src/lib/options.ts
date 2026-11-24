import type { Category } from '@/types'
import type { Option } from '@/components/fields/SearchSelect'

/**
 * شجرة الأصناف كقائمة خيارات مسطّحة.
 *
 * الفرع يُسبق بشرطة لا بمجموعةٍ مستقلّة: البحث في القائمة يطابق النصّ،
 * ومجموعةٌ تختفي عناوينها عند البحث تترك الفروع بلا أب.
 */
export function categoryOptions(categories: Category[] | undefined): Option[] {
  return (categories ?? []).flatMap((category) => [
    { value: String(category.id), label: category.name },
    ...(category.children ?? []).map((child) => ({
      value: String(child.id),
      label: `— ${child.name}`,
    })),
  ])
}
