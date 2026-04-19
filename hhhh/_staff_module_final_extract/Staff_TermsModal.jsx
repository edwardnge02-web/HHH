// src/components/Staff/TermsModal.jsx
import { useState, useEffect } from 'react';
import axios from 'axios';

const API_BASE = 'http://localhost/ewsd1/api';

export default function TermsModal({ isOpen, onClose, onAccept }) {
  const [tcContent, setTcContent] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [hasScrolled, setHasScrolled] = useState(false);

  useEffect(() => {
    if (isOpen) {
      const fetchTC = async () => {
        try {
          const response = await axios.get(`${API_BASE}/staff_ideas.php?action=get_tc`);
          setTcContent(response.data.data?.content || '');
          setIsLoading(false);
        } catch (error) {
          console.error('Failed to fetch T&C');
          setIsLoading(false);
        }
      };

      fetchTC();
    }
  }, [isOpen]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg w-full max-w-2xl max-h-screen overflow-hidden flex flex-col">
        {/* Header */}
        <div className="sticky top-0 bg-white border-b border-gray-200 p-6 flex justify-between items-center">
          <h3 className="text-xl font-bold text-gray-900">Terms and Conditions</h3>
          <button
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700 text-2xl"
          >
            ×
          </button>
        </div>

        {/* Content */}
        <div 
          className="flex-1 overflow-y-auto p-6"
          onScroll={(e) => {
            const element = e.target;
            if (element.scrollHeight - element.scrollTop <= element.clientHeight + 10) {
              setHasScrolled(true);
            }
          }}
        >
          {isLoading ? (
            <p className="text-gray-600">Loading terms...</p>
          ) : (
            <div className="prose prose-sm max-w-none">
              <div className="text-gray-700 whitespace-pre-wrap text-sm leading-relaxed">
                {tcContent}
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="sticky bottom-0 bg-white border-t border-gray-200 p-6 flex gap-3">
          <button
            onClick={onClose}
            className="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition font-medium"
          >
            Decline
          </button>
          <button
            onClick={onAccept}
            disabled={!hasScrolled}
            className={`flex-1 px-4 py-2 rounded-lg transition font-medium ${
              hasScrolled
                ? 'bg-blue-600 text-white hover:bg-blue-700'
                : 'bg-gray-200 text-gray-500 cursor-not-allowed'
            }`}
            title={hasScrolled ? 'Accept T&C' : 'Please scroll to the bottom first'}
          >
            ✓ I Agree & Accept
          </button>
        </div>

        {/* Scroll Hint */}
        {!hasScrolled && (
          <div className="bg-yellow-50 border-t border-yellow-200 px-6 py-3 text-center text-sm text-yellow-800">
            ⬇️ Please scroll down to accept the terms
          </div>
        )}
      </div>
    </div>
  );
}
