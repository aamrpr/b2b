/** لقطات تُصنع في المتصفح قبل الرفع، فلا يولّد الخادم صورة من الفيديو أو من PDF. */

function fit(width: number, height: number, max = 640): { width: number; height: number } {
  const longest = Math.max(width, height, 1)
  const scale = Math.min(1, max / longest)

  return {
    width: Math.max(1, Math.round(width * scale)),
    height: Math.max(1, Math.round(height * scale)),
  }
}

function canvasToJpeg(canvas: HTMLCanvasElement): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('thumb'))), 'image/jpeg', 0.82)
  })
}

function asPoster(blob: Blob): File {
  return new File([blob], 'poster.jpg', { type: 'image/jpeg' })
}

export async function captureImageThumb(file: File): Promise<File> {
  const bitmap = await createImageBitmap(file)
  const size = fit(bitmap.width, bitmap.height)
  const canvas = document.createElement('canvas')
  canvas.width = size.width
  canvas.height = size.height
  const context = canvas.getContext('2d')

  if (!context) {
    bitmap.close()
    throw new Error('thumb')
  }

  context.drawImage(bitmap, 0, 0, size.width, size.height)
  bitmap.close()

  return asPoster(await canvasToJpeg(canvas))
}

/** إطار واحد من ملف الفيديو، يُلتقط محلياً ثم يُرفع مع الملف. */
export function captureVideoFrame(file: File): Promise<File> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const video = document.createElement('video')
    video.preload = 'auto'
    video.muted = true
    video.playsInline = true
    video.src = url
    video.style.cssText = 'position:fixed;left:-9999px;width:1px;height:1px;opacity:0'
    document.body.appendChild(video)

    let settled = false
    const timer = window.setTimeout(() => finish(() => reject(new Error('frame'))), 8000)

    const finish = (done: () => void) => {
      if (settled) return
      settled = true
      window.clearTimeout(timer)
      URL.revokeObjectURL(url)
      video.remove()
      done()
    }

    const draw = () => {
      const width = video.videoWidth
      const height = video.videoHeight

      if (!width || !height) {
        finish(() => reject(new Error('frame')))
        return
      }

      const size = fit(width, height)
      const canvas = document.createElement('canvas')
      canvas.width = size.width
      canvas.height = size.height
      const context = canvas.getContext('2d')

      if (!context) {
        finish(() => reject(new Error('frame')))
        return
      }

      context.drawImage(video, 0, 0, size.width, size.height)
      canvas.toBlob((blob) => {
        finish(() => (blob ? resolve(asPoster(blob)) : reject(new Error('frame'))))
      }, 'image/jpeg', 0.82)
    }

    video.addEventListener('error', () => finish(() => reject(new Error('frame'))))
    video.addEventListener('seeked', draw, { once: true })
    video.addEventListener('loadeddata', () => {
      const duration = Number.isFinite(video.duration) ? video.duration : 0
      const target = duration > 0.2 ? Math.min(0.5, duration * 0.1) : 0

      if (target > 0) video.currentTime = target
      else draw()
    }, { once: true })

    video.load()
  })
}

/** الصفحة الأولى من PDF، تُرسم في المتصفح لا على الخادم. */
export async function capturePdfPoster(file: File): Promise<File> {
  const pdfjs = await import('pdfjs-dist')
  pdfjs.GlobalWorkerOptions.workerSrc = new URL(
    'pdfjs-dist/build/pdf.worker.min.mjs',
    import.meta.url,
  ).toString()

  const doc = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise

  try {
    const page = await doc.getPage(1)
    const base = page.getViewport({ scale: 1 })
    const viewport = page.getViewport({ scale: Math.min(2, 640 / base.width) })
    const canvas = document.createElement('canvas')
    canvas.width = Math.ceil(viewport.width)
    canvas.height = Math.ceil(viewport.height)
    await page.render({ canvas, viewport }).promise

    return asPoster(await canvasToJpeg(canvas))
  } finally {
    await doc.destroy()
  }
}
