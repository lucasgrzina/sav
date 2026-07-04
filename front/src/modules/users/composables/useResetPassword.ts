import { shallowRef } from 'vue'
import { resetPasswordUserApi } from '../api/users.api'
import type { UserItem } from '../types/user.types'

export function useResetPassword() {
  const newPassword = shallowRef('')
  const showResult = shallowRef(false)

  async function resetPassword(user: UserItem) {
    try {
      const result = await resetPasswordUserApi(user.guid)
      newPassword.value = result.password
      showResult.value = true
      return result.password
    } catch {
      // silencioso — el interceptor muestra el error
    }
  }

  return { newPassword, showResult, resetPassword }
}
