import { useEffect, useRef, useState } from 'react'
import { Eraser } from 'lucide-react'
import { useI18n } from '@/i18n'
import { Button } from './ui'

/**
 * لوحة توقيع.
 *
 * التوقيع خطّ يد: يُرسم بالإصبع على الهاتف وبالفأرة على الحاسوب، فالحدث
 * المستعمل Pointer لا Mouse — فيجمع الاثنين وقلم الشاشة معهما.
 *
 * ويُرسم على مضاعفٍ من دقّة الشاشة ثمّ يُصغَّر بالأنماط، وإلّا خرج الخطّ
 * مشوّشاً على الشاشات عالية الكثافة. والناتج PNG شفّاف الخلفية ليظهر
 * التوقيع على الوثيقة لا على مربّع أبيض فوقها.
 */
export function SignaturePad({
  onChange,
  disabled = false,
}: {
  /** يصل data URL بعد كل خطّ، وnull حين تُمسح اللوحة */
  onChange: (dataUrl: string | null) => void
  disabled?: boolean
}) {
  const { t } = useI18n()
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const drawing = useRef(false)
  /* مرجع لا حالة: يُقرأ في نهاية الخطّة نفسها قبل أن تُحدَّث الحالة */
  const inked = useRef(false)
  const [hasInk, setHasInk] = useState(false)

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return

    const ratio = window.devicePixelRatio || 1
    const { width, height } = canvas.getBoundingClientRect()

    canvas.width = width * ratio
    canvas.height = height * ratio

    const context = canvas.getContext('2d')
    if (!context) return

    context.scale(ratio, ratio)
    context.lineWidth = 2
    context.lineCap = 'round'
    context.lineJoin = 'round'
    // لون الحبر ثابت لا يتبع السمة: الوثيقة تُقرأ وتُطبع بالأبيض والأسود
    context.strokeStyle = '#142D4E'
  }, [])

  function pointIn(event: React.PointerEvent<HTMLCanvasElement>) {
    const rect = event.currentTarget.getBoundingClientRect()

    return { x: event.clientX - rect.left, y: event.clientY - rect.top }
  }

  function start(event: React.PointerEvent<HTMLCanvasElement>) {
    if (disabled) return

    const context = canvasRef.current?.getContext('2d')
    if (!context) return

    // الالتقاط يُبقي الخطّ متّصلاً إن خرج الإصبع من اللوحة ثمّ عاد
    event.currentTarget.setPointerCapture(event.pointerId)
    drawing.current = true

    const { x, y } = pointIn(event)
    context.beginPath()
    context.moveTo(x, y)
  }

  function move(event: React.PointerEvent<HTMLCanvasElement>) {
    if (!drawing.current) return

    const context = canvasRef.current?.getContext('2d')
    if (!context) return

    const { x, y } = pointIn(event)
    context.lineTo(x, y)
    context.stroke()
    inked.current = true
    setHasInk(true)
  }

  function end() {
    if (!drawing.current) return

    drawing.current = false
    // نقرةٌ بلا حركة ليست توقيعاً، فلا تُرسل لوحةً فارغة
    onChange(inked.current ? (canvasRef.current?.toDataURL('image/png') ?? null) : null)
  }

  function clear() {
    const canvas = canvasRef.current
    const context = canvas?.getContext('2d')
    if (!canvas || !context) return

    context.clearRect(0, 0, canvas.width, canvas.height)
    inked.current = false
    setHasInk(false)
    onChange(null)
  }

  return (
    <div>
      <div className="flex items-center justify-between gap-3">
        <span className="muted text-xs">{t.quotation.signHint}</span>
        <Button type="button" variant="ghost" size="sm" onClick={clear} disabled={disabled || !hasInk}>
          <Eraser size={14} />
          {t.quotation.clearSignature}
        </Button>
      </div>

      <canvas
        ref={canvasRef}
        onPointerDown={start}
        onPointerMove={move}
        onPointerUp={end}
        onPointerLeave={end}
        // touch-none يمنع المتصفّح من تمرير الصفحة بدل الرسم على الهاتف
        className="mt-1.5 h-40 w-full touch-none rounded-xl border border-dashed border-[var(--border)] bg-white"
      />
    </div>
  )
}
