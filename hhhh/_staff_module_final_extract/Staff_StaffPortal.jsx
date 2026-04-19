// src/pages/StaffPortal.jsx
import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import SubmitIdeaForm from '../components/Staff/SubmitIdeaForm';
import IdeasList from '../components/Staff/IdeasList';
import NotificationsPanel from '../components/Staff/NotificationsPanel';

export default function StaffPortal() {
  const [activeTab, setActiveTab] = useState('browse');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [unreadCount, setUnreadCount] = useState(0);
  
  const navigate = useNavigate();
  const { user, logout, isAuthenticated } = useAuthStore();
  
  useEffect(() => {
    if (!isAuthenticated() || user?.role === 'Admin') {
      navigate('/login');
    }
  }, [navigate, isAuthenticated, user]);
  
  const handleLogout = () => {
    logout();
    navigate('/login');
  };
  
  const handleIdeaSubmitted = () => {
    setSuccess('Idea submitted successfully! 🎉');
    setActiveTab('browse');
    setTimeout(() => setSuccess(''), 5000);
  };
  
  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-50">
      {/* Header */}
      <header className="bg-white border-b border-gray-200 sticky top-0 z-40 shadow-sm">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
          <div className="flex justify-between items-center">
            <div>
              <h1 className="text-2xl font-bold text-gray-900">💡 Ideas Portal</h1>
              <p className="text-sm text-gray-600 mt-1">
                {user?.name || 'Staff Member'} • {user?.department || 'Department'}
              </p>
            </div>
            <div className="flex items-center gap-4">
              <NotificationsPanel onUnreadChange={setUnreadCount} />
              <button
                onClick={handleLogout}
                className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium"
              >
                Logout
              </button>
            </div>
          </div>
        </div>
      </header>
      
      {/* Main Content */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Messages */}
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg flex justify-between items-center">
            <p className="text-red-700 text-sm">{error}</p>
            <button onClick={() => setError('')} className="text-red-700 hover:text-red-900">×</button>
          </div>
        )}
        
        {success && (
          <div className="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg flex justify-between items-center">
            <p className="text-green-700 text-sm">{success}</p>
            <button onClick={() => setSuccess('')} className="text-green-700 hover:text-green-900">×</button>
          </div>
        )}
        
        {/* Navigation Tabs */}
        <div className="flex gap-8 border-b border-gray-200 mb-8 overflow-x-auto">
          {[
            { id: 'browse', label: '🔍 Browse Ideas', icon: '📚' },
            { id: 'submit', label: '✨ Submit Idea', icon: '💡' },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`pb-4 px-1 text-sm font-medium transition-all border-b-2 whitespace-nowrap ${
                activeTab === tab.id
                  ? 'border-blue-600 text-blue-600'
                  : 'border-transparent text-gray-600 hover:text-gray-900'
              }`}
            >
              {tab.icon} {tab.label}
            </button>
          ))}
        </div>
        
        {/* Tab Content */}
        {activeTab === 'browse' && (
          <div>
            <div className="mb-4">
              <p className="text-gray-600 text-sm">
                Explore ideas submitted by your colleagues. Vote, comment, and share feedback!
              </p>
            </div>
            <IdeasList onError={setError} />
          </div>
        )}
        
        {activeTab === 'submit' && (
          <div>
            <div className="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
              <h2 className="text-lg font-semibold text-blue-900 mb-2">📝 Share Your Ideas</h2>
              <p className="text-sm text-blue-800">
                Have a great idea? Share it with your department! Your feedback helps us improve.
              </p>
            </div>
            <SubmitIdeaForm 
              onSuccess={handleIdeaSubmitted}
              onError={setError}
            />
          </div>
        )}
      </main>
    </div>
  );
}
