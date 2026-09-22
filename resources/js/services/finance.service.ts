import axiosClient from '../axios'

const baseUrl = '/api/finance'

const financeService = {
  async getRefunds(params?: any) {
    const response = await axiosClient.get(`${baseUrl}/refunds`, { params })
    return response.data
  },

  async getRefund(id: number | string) {
    const response = await axiosClient.get(`${baseUrl}/refunds/${id}`)
    return response.data
  },

  async createRefund(payload: any) {
    const response = await axiosClient.post(`${baseUrl}/refunds`, payload)
    return response.data
  },

  async updateRefundStatus(id: number | string, payload: { status: 'approved' | 'rejected'; notes?: string }) {
    const response = await axiosClient.put(`${baseUrl}/refunds/${id}/status`, payload)
    return response.data
  },

  async markRefundSent(id: number | string, payload: { provider: 'paymongo' | 'manual'; payout_reference?: string; notes?: string }) {
    const response = await axiosClient.post(`${baseUrl}/refunds/${id}/send`, payload)
    return response.data
  },

  async getDashboard() {
    const response = await axiosClient.get(`${baseUrl}/dashboard`)
    return response.data
  },

  async approvePurchaseOrder(id: number) {
    const response = await axiosClient.post(`${baseUrl}/purchase-orders/${id}/approve`)
    return response.data
  },

  async rejectPurchaseOrder(id: number, payload: { reason: string }) {
      const response = await axiosClient.post(`${baseUrl}/purchase-orders/${id}/reject`, payload)
      return response.data
    },

  async getPurchaseOrders(params?: any) {
    const response = await axiosClient.get(`${baseUrl}/purchase-orders`, { params })
    return response.data
  },

  async getPurchaseOrder(id: number | string) {
    const response = await axiosClient.get(`${baseUrl}/purchase-orders/${id}`)
    return response.data
  },

  async createPurchaseOrder(payload: any) {
    const response = await axiosClient.post(`${baseUrl}/purchase-orders`, payload)
    return response.data
  },

  async updatePurchaseOrder(id: number | string, payload: any) {
    const response = await axiosClient.put(`${baseUrl}/purchase-orders/${id}`, payload)
    return response.data
  },

  async deletePurchaseOrder(id: number | string) {
    const response = await axiosClient.delete(`${baseUrl}/purchase-orders/${id}`)
    return response.data
  },

  async sendPurchaseOrder(id: number | string) {
    const response = await axiosClient.post(`${baseUrl}/purchase-orders/${id}/send`)
    return response.data
  },

  async cancelPurchaseOrder(id: number | string) {
    const response = await axiosClient.post(`${baseUrl}/purchase-orders/${id}/cancel`)
    return response.data
  },

  async summaryPurchaseOrders() {
    const response = await axiosClient.get(`${baseUrl}/purchase-orders/summary`)
    return response.data
  },

  async printPurchaseOrder(id: number | string) {
    const response = await axiosClient.get(`${baseUrl}/purchase-orders/${id}/print`, { responseType: 'blob' })
    return response
  },

  async emailPurchaseOrder(id: number | string, payload: any) {
    const response = await axiosClient.post(`${baseUrl}/purchase-orders/${id}/email`, payload)
    return response.data
  },

  async labelPurchaseOrder(id: number | string) {
    const response = await axiosClient.get(`${baseUrl}/purchase-orders/${id}/label`, { responseType: 'blob' })
    return response
  },

  async requestRevisionPurchaseOrder(id: number | string, payload: any) {
    const response = await axiosClient.post(`${baseUrl}/purchase-orders/${id}/request-revision`, payload)
    return response.data
  },

  async getPendingReceipt(id: number | string) {
    const response = await axiosClient.get(`${baseUrl}/purchase-orders/${id}/pending-receipt`)
    return response.data
  },

  async getPayables(params?: any) {
    const response = await axiosClient.get(`${baseUrl}/payables`, { params })
    return response.data
  },

  async getInvoice(id: number) {
    const response = await axiosClient.get(`${baseUrl}/invoices/${id}`)
    return response.data
  },

  async getInvoices(params?: any) {
    const response = await axiosClient.get(`${baseUrl}/invoices`, { params })
    return response.data
  },

  async matchInvoice(id: number) {
    const response = await axiosClient.post(`${baseUrl}/invoices/${id}/match`)
    return response.data
  },

  async approveInvoice(id: number) {
    const response = await axiosClient.post(`${baseUrl}/invoices/${id}/approve`)
    return response.data
  },

  async markInvoicePaid(id: number, payload: { payment_method: string; payment_amount: number }) {
    const response = await axiosClient.post(`${baseUrl}/invoices/${id}/mark-paid`, payload)
    return response.data
  },

  async getSupplierPayments(params?: any) {
    const response = await axiosClient.get('/api/procurement/payments', { params })
    return response.data
  },

  async approveSupplierPayment(id: number) {
    const response = await axiosClient.post(`/api/procurement/payments/${id}/approve`)
    return response.data
  },

  async processSupplierPayment(id: number) {
    const response = await axiosClient.post(`/api/procurement/payments/${id}/process`)
    return response.data
  },

  async getReceivables(params?: any) {
    const response = await axiosClient.get(`${baseUrl}/receivables`, { params })
    return response.data
  },

  async getCashflowAccount() {
    const response = await axiosClient.get(`${baseUrl}/cashflow/account`)
    return response.data
  },

  async getCashflowTransactions(params?: any) {
    const response = await axiosClient.get(`${baseUrl}/cashflow/transactions`, { params })
    return response.data
  },

  async adjustCashflow(payload: {
    direction: 'in' | 'out'
    amount: number
    payment_method?: string
    description?: string
    notes?: string
  }) {
    const response = await axiosClient.post(`${baseUrl}/cashflow/adjust`, payload)
    return response.data
  },

  async topUpCashflow(payload: {
    amount: number
    payment_method?: string
    description?: string
    notes?: string
  }) {
    const response = await axiosClient.post(`${baseUrl}/cashflow/top-up`, payload)
    return response.data
  },

  async getReceivableDetail(source: 'sales' | 'ecommerce', id: number | string) {
    const response = await axiosClient.get(`${baseUrl}/receivables/${source}/${id}`)
    return response.data
  },

  async getExpenses(params?: any) {
    const response = await axiosClient.get(`${baseUrl}/expenses`, { params })
    return response.data
  },

  async getExpenseDetail(id: number | string) {
    const response = await axiosClient.get(`${baseUrl}/expenses/${id}`)
    return response.data
  },

  async createExpense(payload: any) {
    const response = await axiosClient.post(`${baseUrl}/expenses`, payload)
    return response.data
  },

  async updateExpense(id: number, payload: any) {
    const response = await axiosClient.put(`${baseUrl}/expenses/${id}`, payload)
    return response.data
  },

  async approveExpense(id: number) {
    const response = await axiosClient.post(`${baseUrl}/expenses/${id}/approve`)
    return response.data
  },

  async rejectExpense(id: number, payload: { notes: string }) {
    const response = await axiosClient.post(`${baseUrl}/expenses/${id}/reject`, payload)
    return response.data
  },

  async markExpensePaid(id: number, payload: any) {
    const response = await axiosClient.post(`${baseUrl}/expenses/${id}/mark-paid`, payload)
    return response.data
  },

  async deleteExpense(id: number) {
    const response = await axiosClient.delete(`${baseUrl}/expenses/${id}`)
    return response.data
  },

  async getBudgets(params?: any) {
    const response = await axiosClient.get(`${baseUrl}/budgets`, { params })
    return response.data
  },

  async createBudget(payload: any) {
    const response = await axiosClient.post(`${baseUrl}/budgets`, payload)
    return response.data
  },

  async updateBudget(id: number, payload: any) {
    const response = await axiosClient.put(`${baseUrl}/budgets/${id}`, payload)
    return response.data
  },

  async deleteBudget(id: number) {
    const response = await axiosClient.delete(`${baseUrl}/budgets/${id}`)
    return response.data
  },

  async getPayroll(params?: any) {
    const response = await axiosClient.get(`${baseUrl}/payroll`, { params })
    return response.data
  },

  async getPayrollPeriodDetail(payPeriodId: number | string, params?: any) {
    const response = await axiosClient.get(`/api/payroll/pay-periods/${payPeriodId}/payroll`, { params })
    return response.data
  },

  async submitPayroll(id: number) {
    const response = await axiosClient.post(`/api/payroll/${id}/submit`)
    return response.data
  },

  async bulkSubmitPayroll(payrollIds: Array<number | string>) {
    const response = await axiosClient.post('/api/payroll/bulk-submit', { payroll_ids: payrollIds })
    return response.data
  },

  async bulkApprovePayroll(payrollIds: Array<number | string>) {
    const response = await axiosClient.post('/api/payroll/bulk-approve', { payroll_ids: payrollIds })
    return response.data
  },

  async approvePayroll(id: number) {
    const response = await axiosClient.post(`/api/payroll/${id}/approve`)
    return response.data
  },

  async releasePayroll(id: number, payload?: { notes?: string }) {
    const response = await axiosClient.post(`/api/payroll/${id}/release`, payload || {})
    return response.data
  },

  async markPayrollPaid(
    id: number,
    payload?: {
      payment_date?: string
      payment_method?: string
      reference_number?: string
      notes?: string
    }
  ) {
    const normalizedPayload = {
      payment_date: payload?.payment_date ?? new Date().toISOString().slice(0, 10),
      payment_method: payload?.payment_method ?? 'bank_transfer',
      reference_number: payload?.reference_number,
      notes: payload?.notes,
    }

    const response = await axiosClient.post(`/api/payroll/${id}/mark-paid`, normalizedPayload)
    return response.data
  },

  async bulkMarkPayrollPaid(
    payrollIds: Array<number | string>,
    payload?: {
      payment_date?: string
      payment_method?: string
      reference_number?: string
      notes?: string
    }
  ) {
    const normalizedPayload = {
      payroll_ids: payrollIds,
      payment_date: payload?.payment_date ?? new Date().toISOString().slice(0, 10),
      payment_method: payload?.payment_method ?? 'bank_transfer',
      reference_number: payload?.reference_number,
      notes: payload?.notes,
    }

    const response = await axiosClient.post('/api/payroll/bulk-mark-paid', normalizedPayload)
    return response.data
  },
}

export default financeService
