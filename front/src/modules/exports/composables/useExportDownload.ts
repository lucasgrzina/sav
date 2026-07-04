import { http } from '@/core/api/http'

export function useExportDownload() {
  async function downloadExport(guid: string): Promise<void> {
    const response = await http.get(`/v1/exports/${guid}/download`, {
      responseType: 'blob',
    })

    const contentDisposition = response.headers['content-disposition'] as string | undefined
    let fileName = `export_${guid}`

    if (contentDisposition) {
      const match = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
      if (match?.[1]) {
        fileName = match[1].replace(/['"]/g, '').trim()
      }
    }

    const url = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', fileName)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  }

  return { downloadExport }
}
