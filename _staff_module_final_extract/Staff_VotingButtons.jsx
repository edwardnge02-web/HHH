// src/components/Staff/VotingButtons.jsx
import { useState } from 'react';
import axios from 'axios';

const API_BASE = 'http://localhost/ewsd1/api';

export default function VotingButtons({ idea, onError }) {
  const [upvotes, setUpvotes] = useState(idea.upvote_count || 0);
  const [downvotes, setDownvotes] = useState(idea.downvote_count || 0);
  const [userVote, setUserVote] = useState(idea.user_vote || null);
  const [isLoading, setIsLoading] = useState(false);

  const handleVote = async (voteType) => {
    if (userVote) {
      onError?.('You have already voted on this idea');
      return;
    }

    setIsLoading(true);
    try {
      await axios.post(
        `${API_BASE}/staff_ideas.php?action=vote_idea`,
        {
          idea_id: idea.id,
          vote_type: voteType
        },
        { headers: { 'Authorization': `Bearer ${localStorage.getItem('authToken')}` } }
      );

      setUserVote(voteType);
      if (voteType === 'up') {
        setUpvotes(upvotes + 1);
      } else {
        setDownvotes(downvotes + 1);
      }
    } catch (error) {
      onError?.(error.response?.data?.message || 'Failed to vote');
    } finally {
      setIsLoading(false);
    }
  };

  const netVotes = upvotes - downvotes;

  return (
    <div className="flex items-center gap-6">
      {/* Upvote Button */}
      <button
        onClick={() => handleVote('up')}
        disabled={isLoading || userVote !== null}
        className={`flex items-center gap-2 px-4 py-2 rounded-lg transition font-medium ${
          userVote === 'up'
            ? 'bg-green-100 text-green-700 cursor-not-allowed'
            : userVote
            ? 'bg-gray-100 text-gray-600 cursor-not-allowed'
            : 'bg-green-50 text-green-600 hover:bg-green-100 border border-green-200'
        }`}
        title={userVote ? 'You have already voted on this idea' : 'Vote up'}
      >
        <span className="text-lg">👍</span>
        <span>{upvotes}</span>
      </button>

      {/* Net Votes */}
      <div className="text-center px-4">
        <p className="text-sm text-gray-600">Net Votes</p>
        <p className={`text-2xl font-bold ${
          netVotes > 0 ? 'text-green-600' : netVotes < 0 ? 'text-red-600' : 'text-gray-600'
        }`}>
          {netVotes > 0 ? '+' : ''}{netVotes}
        </p>
      </div>

      {/* Downvote Button */}
      <button
        onClick={() => handleVote('down')}
        disabled={isLoading || userVote !== null}
        className={`flex items-center gap-2 px-4 py-2 rounded-lg transition font-medium ${
          userVote === 'down'
            ? 'bg-red-100 text-red-700 cursor-not-allowed'
            : userVote
            ? 'bg-gray-100 text-gray-600 cursor-not-allowed'
            : 'bg-red-50 text-red-600 hover:bg-red-100 border border-red-200'
        }`}
        title={userVote ? 'You have already voted on this idea' : 'Vote down'}
      >
        <span className="text-lg">👎</span>
        <span>{downvotes}</span>
      </button>

      {/* Vote Status */}
      {userVote && (
        <p className="text-sm text-gray-600 italic">
          ✓ You voted {userVote === 'up' ? '👍' : '👎'}
        </p>
      )}
    </div>
  );
}
