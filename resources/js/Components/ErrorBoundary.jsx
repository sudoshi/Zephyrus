import React from 'react';
import PropTypes from 'prop-types';

class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  componentDidCatch(error, errorInfo) {
    // You can log the error to an error reporting service here
    console.error('Error caught by boundary:', error, errorInfo);
  }

  render() {
    if (this.state.hasError) {
      // Optional custom fallback: a render function (receives the error) or a
      // node. Falls back to the default inline message when not provided, so
      // existing call sites are unaffected.
      if (this.props.fallback) {
        return typeof this.props.fallback === 'function'
          ? this.props.fallback(this.state.error)
          : this.props.fallback;
      }
      return (
        <div className="p-4 rounded-md bg-healthcare-critical/10 dark:bg-healthcare-critical/20 border border-healthcare-critical/20 dark:border-healthcare-critical/30">
          <div className="flex">
            <div className="flex-shrink-0">
              <svg className="h-5 w-5 text-healthcare-critical dark:text-healthcare-critical-dark" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
              </svg>
            </div>
            <div className="ml-3">
              <h3 className="text-sm font-medium text-healthcare-critical dark:text-healthcare-critical-dark">
                {this.props.fallbackText || 'Something went wrong'}
              </h3>
              {this.props.showError && (
                <div className="mt-2 text-sm text-healthcare-critical dark:text-healthcare-critical-dark">
                  {this.state.error?.message}
                </div>
              )}
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}

ErrorBoundary.propTypes = {
  children: PropTypes.node.isRequired,
  fallbackText: PropTypes.string,
  showError: PropTypes.bool,
  fallback: PropTypes.oneOfType([PropTypes.node, PropTypes.func]),
};

ErrorBoundary.defaultProps = {
  fallbackText: 'Something went wrong',
  showError: false,
};

export default ErrorBoundary;
