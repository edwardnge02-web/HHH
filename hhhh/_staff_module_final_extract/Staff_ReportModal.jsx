// src/components/Staff/ReportModal.jsx
import { useState } from 'react';
import axios from 'axios';

const API_BASE = 'http://localhost/ewsd1/api';

export default function ReportModal({ isOpen, onClose, contentType, contentId, onSuccess }) {
  const [form, setForm] = useState({
    report_category: 'Swearing',
    reason: '',
    description: '',
    severity: 'Medium'
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState('');

  const categories = [
    { value: 'Swearing', label: '🤐 Swearing / Offensive Language' },
    { value: 'Libel', label: '⚖️ Libel / Defamation' },
    { value: 'Harassment', label: '🎯 Harassment' },
    { value: 'Offensive', label: '😠 Offensive Content' },
    { value: 'Other', label: '❓ Other' },
  ];

  const severityLevels = [
    { value: 'Low', label: 'Low - Minor violation' },
    { value: 'Medium', label: 'Medium - Should be reviewed' },
    { value: 'High', label: 'High - Serious violation' },
    { value: 'Critical', label: 'Critical - Immediate action needed' },
  ];

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!form.reason.trim()) {
      setError('Reason is required');
      return;
    }

    setIsSubmitting(true);
    try {
      await axios.post(
        `${API_BASE}/staff_comments.php?action=report_content`,
        {
          content_type: contentType,
          content_id: contentId,
          report_category: form.report_category,
          reason: form.reason,
          description: form.description,
          severity: form.severity
        },
        { headers: { 'Authorization': `Bearer ${localStorage.getItem('authToken')}` } }
      );

      setForm({
        report_category: 'Swearing',
        reason: '',
        description: '',
        severity: 'Medium'
      });
      setError('');
      onSuccess?.();
      onClose();
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to submit report');
    } finally {
      setIsSubmitting(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg w-full max-w-md">
        {/* Header */}
        <div className="border-b border-gray-200 p-6 flex justify-between items-center">
          <h3 className="text-lg font-bold text-gray-900">Report Inappropriate Content</h3>
          <button
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700 text-2xl"
          >
            ×
          </button>
        </div>

        {/* Content */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          {error && (
            <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
              {error}
            </div>
          )}

          {/* Category */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Category *
            </label>
            <select
              value={form.report_category}
              onChange={(e) => setForm({ ...form, report_category: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"
            >
              {categories.map(cat => (
                <option key={cat.value} value={cat.value}>
                  {cat.label}
                </option>
              ))}
            </select>
          </div>

          {/* Reason */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Reason *
            </label>
            <input
              type="text"
              value={form.reason}
              onChange={(e) => setForm({ ...form, reason: e.target.value })}
              placeholder="Brief reason for reporting"
              maxLength="200"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"
            />
            <p className="text-xs text-gray-500 mt-1">{form.reason.length}/200</p>
          </div>

          {/* Description */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Description
            </label>
            <textarea
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              placeholder="Additional details..."
              rows="3"
              maxLength="500"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"
            />
            <p className="text-xs text-gray-500 mt-1">{form.description.length}/500</p>
          </div>

          {/* Severity */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Severity
            </label>
            <select
              value={form.severity}
              onChange={(e) => setForm({ ...form, severity: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"
            >
              {severityLevels.map(level => (
                <option key={level.value} value={level.value}>
                  {level.label}
                </option>
              ))}
            </select>
          </div>

          {/* Buttons */}
          <div className="flex gap-2 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition font-medium"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              className="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 transition font-medium"
            >
              {isSubmitting ? 'Submitting...' : '⚠️ Submit Report'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
