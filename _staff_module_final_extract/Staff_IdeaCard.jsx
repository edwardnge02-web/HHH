// src/components/Staff/IdeaCard.jsx
export default function IdeaCard({ idea, onClick }) {
  const netVotes = (idea.upvote_count || 0) - (idea.downvote_count || 0);

  return (
    <button
      onClick={onClick}
      className="w-full text-left bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md hover:border-blue-300 transition"
    >
      {/* Title */}
      <h3 className="text-lg font-semibold text-gray-900 mb-2 hover:text-blue-600 transition">
        {idea.title}
      </h3>

      {/* Description Preview */}
      <p className="text-sm text-gray-600 mb-3 line-clamp-2">
        {idea.description}
      </p>

      {/* Meta Information */}
      <div className="flex flex-wrap gap-4 text-xs text-gray-600 mb-3">
        <span>👤 {idea.is_anonymous ? 'Anonymous' : idea.contributor_name}</span>
        <span>📁 {idea.department}</span>
        <span>📅 {new Date(idea.submitted_at).toLocaleDateString()}</span>
      </div>

      {/* Metrics */}
      <div className="flex gap-4 text-sm">
        <div className="flex items-center gap-1">
          <span className="text-lg">👁️</span>
          <span className="text-gray-600">{idea.view_count || 0}</span>
        </div>
        <div className="flex items-center gap-1">
          <span className="text-lg">💬</span>
          <span className="text-gray-600">{idea.comment_count || 0}</span>
        </div>
        <div className={`flex items-center gap-1 font-semibold ${
          netVotes > 0 ? 'text-green-600' : netVotes < 0 ? 'text-red-600' : 'text-gray-600'
        }`}>
          <span className="text-lg">{netVotes > 0 ? '👍' : netVotes < 0 ? '👎' : '➖'}</span>
          <span>{netVotes > 0 ? '+' : ''}{netVotes}</span>
        </div>
      </div>

      {/* Category Tags */}
      {idea.category_name && (
        <div className="mt-3">
          <span className="inline-block px-2 py-1 bg-blue-100 text-blue-700 text-xs rounded">
            {idea.category_name}
          </span>
        </div>
      )}
    </button>
  );
}
