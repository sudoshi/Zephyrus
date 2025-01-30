import axios from 'axios';
import { mockMetrics, mockTodaysCases, mockRoomStatus, mockServices, mockSurgeons } from '../mock-data/dashboard';
import { mockBlockTemplates, mockBlockUtilization, mockBlockSchedule, mockBlockStatistics } from '../mock-data/block-schedule';
import { mockPerformanceMetrics, mockSurgeonScorecard, mockCapacityAnalysis, mockEfficiencyMetrics } from '../mock-data/analytics';

// Toggle this to switch between mock data and real API calls
const USE_MOCK_DATA = true;

class DataService {
    // Dashboard Data
    async getDashboardMetrics() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockMetrics);
        }
        return axios.get('/api/cases/metrics').then(response => response.data);
    }

    async getTodaysCases() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockTodaysCases);
        }
        return axios.get('/api/cases/today').then(response => response.data);
    }

    async getRoomStatus() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockRoomStatus);
        }
        return axios.get('/api/cases/room-status').then(response => response.data);
    }

    // Block Schedule Data
    async getBlockTemplates() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockBlockTemplates);
        }
        return axios.get('/api/blocks/templates').then(response => response.data);
    }

    async getBlockSchedule(date) {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockBlockSchedule[date] || []);
        }
        return axios.get(`/api/blocks/schedule/${date}`).then(response => response.data);
    }

    async getBlockUtilization(date) {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockBlockUtilization);
        }
        return axios.get(`/api/blocks/utilization/${date}`).then(response => response.data);
    }

    async getBlockStatistics() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockBlockStatistics);
        }
        return axios.get('/api/blocks/statistics').then(response => response.data);
    }

    // Analytics Data
    async getPerformanceMetrics() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockPerformanceMetrics);
        }
        return axios.get('/api/analytics/performance').then(response => response.data);
    }

    async getSurgeonScorecard(surgeonId) {
        if (USE_MOCK_DATA) {
            const surgeon = surgeonId === 1 ? 'Dr. Sarah Johnson' : 'Dr. Michael Smith';
            return Promise.resolve(mockSurgeonScorecard[surgeon]);
        }
        return axios.get(`/api/analytics/surgeon/${surgeonId}`).then(response => response.data);
    }

    async getCapacityAnalysis() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockCapacityAnalysis);
        }
        return axios.get('/api/analytics/capacity').then(response => response.data);
    }

    async getEfficiencyMetrics() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockEfficiencyMetrics);
        }
        return axios.get('/api/analytics/efficiency').then(response => response.data);
    }

    // Reference Data
    async getServices() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockServices);
        }
        return axios.get('/api/services').then(response => response.data);
    }

    async getSurgeons() {
        if (USE_MOCK_DATA) {
            return Promise.resolve(mockSurgeons);
        }
        return axios.get('/api/surgeons').then(response => response.data);
    }

    // Error Handler
    handleError(error) {
        console.error('DataService Error:', error);
        if (error.response) {
            // Server responded with error
            return Promise.reject(error.response.data);
        } else if (error.request) {
            // Request made but no response
            return Promise.reject({ message: 'No response from server' });
        } else {
            // Request setup error
            return Promise.reject({ message: error.message });
        }
    }
}

export default new DataService();
