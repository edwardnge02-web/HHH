// src/components/Staff/IdeasList.jsx
import { useState, useEffect } from 'react';
import axios from 'axios';
import FilterTabs from './FilterTabs';
import IdeaCard from './IdeaCard';

const API_BASE = 'http://localhost/ewsd1/api';

export default function IdeasList({ onError, onSelectIdea }) {
  const [ideas, setIdeas] = useState([]);
  const [sessions, setSessions] = useState([]);
  const [filter, setFilter] = useState('latest');
  const [page, setPage] = useState(1);
  const [totalIdeas, setTotalIdeas] = useState(0);
  const [selectedSession, setSelectedSession] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  
  const perPage = 5;

  // Fetch sessions
  useEffect(() => {
    const fetchSessions = async () => {
      try {
        const response = await axios.get(
          `${API_BASE}/qa_coordinator.php?action=sessions_closure_dates`,
          { headers: { 'Authorization': `Bearer ${localStorage.getItem('authToken')}` } }
        );
        setSessions(response.data.data || []);
        if (response.data.data?.length > 0) {
          setSelectedSession(response.data.data[0].id);
        }
      } catch (error) {
        console.error('Failed to fetch sessions');
      }
    };
    
    fetchSessions();
  }, []);

  // Fetch ideas
  useEffect(() => {
    const fetchIdeas = async () => {
      if (!selectedSession) return;
      
      setIsLoading(true);
      try {
        let url = `${API_BASE}/staff_ideas.php?action=get_ideas&filter=${filter}&page=${page}&per_page=${perPage}`;
        if (selectedSession) {
          url += `&session_id=${selectedSession}`;
        }

        const response = await axios.get(url, {
          headers: { 'Authorization': `Bearer ${localStorage.getItem('authToken')}` }
        });

        setIdeas(response.data.data || []);
        setTotalIdeas(response.data.pagination?.total || 0);
        onError('');
      } catch (error) {
        onError(error.response?.data?.message || 'Failed to fetch ideas');
      } finally {
        setIsLoading(false);
      }
    };

    if (selectedSession) {
      fetchIdeas();
    }
  }, [selectedSession, filter, page]);

  const handleFilterChange = (newFilter) => {
    setFilter(newFilter);
    setPage(1);
  };

  return (
    <div className="space-y-6">
      {/* Session Selector */}
      <div className="bg-white rounded-lg border border-gray-200 p-4">
        <label className="block text-sm font-medium text-gray-700 mb-2">
          Select Session
        </label>
        <select
          value={selectedSession}
          onChange={(e) => {
            setSelectedSession(e.target.value);
            setPage(1);
          }}
          className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"
        >
          <option value="">Choose a session</option>
          {sessions.map(session => (
            <option key={session.id} value={session.id}>
              {session.session_name} ({session.days_until_closure}d left)
            </option>
          ))}
        </select>
      </div>

      {/* Filter Tabs */}
      <FilterTabs 
        activeFilter={filter}
        onFilterChange={handleFilterChange}
      />

      {/* Ideas List */}
      {isLoading ? (
        <div className="text-center py-12">
          <p className="text-gray-600">Loading ideas...</p>
        </div>
      ) : ideas.length === 0 ? (
        <div className="text-center py-12 bg-white rounded-lg border border-gray-200">
          <p className="text-gray-600 text-lg">No ideas yet</p>
          <p className="text-gray-500 text-sm mt-2">Be the first to share an idea!</p>
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4">
            {ideas.map(idea => (
              <IdeaCard
                key={idea.id}
                idea={idea}
                onClick={() => onSelectIdea?.(idea.id)}
              />
            ))}
          </div>

          {/* Pagination */}
          {totalIdeas > perPage && (
            <div className="flex justify-center items-center gap-4 py-6">
              <button
                onClick={() => setPage(Math.max(1, page - 1))}
                disabled={page === 1}
                className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 transition"
              >
                ← Previous
              </button>
              <span className="text-gray-700 font-medium">
                Page {page} of {Math.ceil(totalIdeas / perPage)}
              </span>
              <button
                onClick={() => setPage(page + 1)}
                disabled={page * perPage >= totalIdeas}
                className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 transition"
              >
                Next →
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
