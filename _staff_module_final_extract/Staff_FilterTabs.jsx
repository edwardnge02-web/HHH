// src/components/Staff/FilterTabs.jsx
export default function FilterTabs({ activeFilter, onFilterChange }) {
  const filters = [
    { id: 'latest', label: '📅 Latest Ideas', description: 'Most recently submitted' },
    { id: 'popular', label: '👍 Most Popular', description: 'Highest rated' },
    { id: 'unpopular', label: '👎 Least Popular', description: 'Lowest rated' },
    { id: 'viewed', label: '👁️ Most Viewed', description: 'Most views' },
    { id: 'comments', label: '💬 Most Discussed', description: 'Most comments' },
  ];

  return (
    <div className="bg-white rounded-lg border border-gray-200 p-4">
      <p className="text-sm font-medium text-gray-700 mb-3">Filter Ideas</p>
      
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-2">
        {filters.map(filter => (
          <button
            key={filter.id}
            onClick={() => onFilterChange(filter.id)}
            className={`p-3 rounded-lg border-2 transition text-left ${
              activeFilter === filter.id
                ? 'border-blue-600 bg-blue-50 text-blue-900'
                : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50'
            }`}
          >
            <p className="font-medium text-sm">{filter.label}</p>
            <p className="text-xs text-gray-600 mt-1">{filter.description}</p>
          </button>
        ))}
      </div>
    </div>
  );
}
