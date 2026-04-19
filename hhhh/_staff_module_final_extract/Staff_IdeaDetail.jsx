// src/components/Staff/IdeaDetail.jsx
import { useState, useEffect } from 'react';
import axios from 'axios';
import CommentsSection from './CommentsSection';
import VotingButtons from './VotingButtons';
import ReportModal from './ReportModal';

const API_BASE = 'http://localhost/ewsd1/api';

export default function IdeaDetail({ ideaId, onClose }) {
  const [idea, setIdea] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState('');
  const [showReport, setShowReport] = useState(false);

  useEffect(() => {
    const fetchIdea = async () => {
      setIsLoading(true);
      try {
        const response = await axios.get(
          `${API_BASE}/staff_ideas.php?action=get_idea&idea_id=${ideaId}`,
          { headers: { 'Authorization': `Bearer ${localStorage.getItem('authToken')}` } }
        );
        setIdea(response.data.data);
        setError('');
      } catch (err) {
        setError(err.response?.data?.message || 'Failed to load idea');
      } finally {
        setIsLoading(false);
      }
    };

    if (ideaId) {
      fetchIdea();
    }
  }, [ideaId]);

  if (isLoading) {
    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div className="bg-white rounded-lg w-full max-w-3xl max-h-96 overflow-y-auto p-6">
          <p className="text-gray-600">Loading idea...</p>
        </div>
      </div>
    );
  }

  if (error || !idea) {
    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div className="bg-white rounded-lg w-full max-w-3xl p-6">
          <p className="text-red-600">{error || 'Idea not found'}</p>
          <button
            onClick={onClose}
            className="mt-4 px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300"
          >
            Close
          </button>
        </div>
      </div>
    );
  }

  const daysUntilClosure = idea.closes_at ? 
    Math.ceil((new Date(idea.closes_at) - new Date()) / (1000 * 60 * 60 * 24)) : 0;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg w-full max-w-4xl max-h-screen overflow-y-auto">
        {/* Header */}
        <div className="sticky top-0 border-b border-gray-200 p-6 bg-white flex justify-between items-start">
          <div className="flex-1">
            <h2 className="text-2xl font-bold text-gray-900">{idea.title}</h2>
            <p className="text-sm text-gray-600 mt-2">
              {idea.is_anonymous ? 'Anonymous' : idea.contributor_name} • {idea.department}
            </p>
          </div>
          <button
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700 text-2xl"
          >
            ×
          </button>
        </div>

        {/* Content */}
        <div className="p-6">
          {/* Session Status */}
          <div className="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
            <div className="flex justify-between items-center">
              <div>
                <p className="text-sm font-medium text-blue-900">Session: {idea.session_name}</p>
                <p className="text-xs text-blue-700 mt-1">
                  {idea.can_submit ? `Submissions open for ${daysUntilClosure} more days` : 'Submissions closed'}
                </p>
              </div>
              {idea.can_comment ? (
                <span className="px-3 py-1 bg-green-100 text-green-700 text-xs font-medium rounded">
                  💬 Comments Open
                </span>
              ) : (
                <span className="px-3 py-1 bg-red-100 text-red-700 text-xs font-medium rounded">
                  ❌ Session Closed
                </span>
              )}
            </div>
          </div>

          {/* Description */}
          <div className="mb-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Description</h3>
            <p className="text-gray-700 whitespace-pre-wrap">{idea.description}</p>
          </div>

          {/* Attachments */}
          {idea.attachments && idea.attachments.length > 0 && (
            <div className="mb-6">
              <h3 className="text-lg font-semibold text-gray-900 mb-3">Attachments</h3>
              <div className="space-y-2">
                {idea.attachments.map(file => (
                  <div key={file.id} className="flex items-center gap-2 p-3 bg-gray-50 rounded">
                    <span className="text-lg">📎</span>
                    <a
                      href={file.file_path}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-blue-600 hover:text-blue-700 underline flex-1"
                    >
                      {file.file_name}
                    </a>
                    <span className="text-xs text-gray-500">
                      {(file.file_size / 1024 / 1024).toFixed(1)} MB
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Engagement Metrics */}
          <div className="mb-6 grid grid-cols-3 gap-4">
            <div className="p-3 bg-gray-50 rounded-lg text-center">
              <p className="text-2xl font-bold text-gray-900">{idea.view_count || 0}</p>
              <p className="text-xs text-gray-600">Views</p>
            </div>
            <div className="p-3 bg-gray-50 rounded-lg text-center">
              <p className="text-2xl font-bold text-gray-900">{idea.comment_count || 0}</p>
              <p className="text-xs text-gray-600">Comments</p>
            </div>
            <div className="p-3 bg-gray-50 rounded-lg text-center">
              <p className="text-2xl font-bold text-gray-900">
                {(idea.upvote_count || 0) - (idea.downvote_count || 0)}
              </p>
              <p className="text-xs text-gray-600">Net Votes</p>
            </div>
          </div>

          {/* Voting */}
          <div className="mb-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-3">Your Vote</h3>
            <VotingButtons idea={idea} />
          </div>

          {/* Comments Section */}
          {idea.can_comment && (
            <div className="mb-6">
              <CommentsSection ideaId={idea.id} />
            </div>
          )}

          {/* Report Button */}
          <div className="flex gap-2">
            <button
              onClick={() => setShowReport(true)}
              className="flex-1 px-4 py-2 border border-red-300 text-red-600 rounded-lg hover:bg-red-50 transition font-medium"
            >
              ⚠️ Report Inappropriate Content
            </button>
          </div>
        </div>
      </div>

      {/* Report Modal */}
      <ReportModal
        isOpen={showReport}
        onClose={() => setShowReport(false)}
        contentType="Idea"
        contentId={idea.id}
      />
    </div>
  );
}
