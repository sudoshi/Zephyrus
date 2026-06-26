import React from 'react';

class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  componentDidCatch(error, errorInfo) {
    console.error('Error caught by boundary:', error, errorInfo);
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="p-4 bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20 border border-healthcare-critical/20 dark:border-healthcare-critical-dark/30 rounded-lg">
          <h3 className="text-lg font-semibold text-healthcare-critical dark:text-healthcare-critical-dark mb-2">
            Something went wrong
          </h3>
          <p className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">
            {this.state.error?.message || 'An unexpected error occurred'}
          </p>
          <button
            onClick={() => this.setState({ hasError: false, error: null })}
            className="mt-4 px-4 py-2 text-sm font-medium text-healthcare-critical dark:text-healthcare-critical-dark bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20 rounded-md hover:bg-healthcare-critical/20 dark:hover:bg-healthcare-critical-dark/30"
          >
            Try again
          </button>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
