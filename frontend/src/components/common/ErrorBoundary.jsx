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
    console.error('ErrorBoundary caught:', error, errorInfo);
    this.setState({ error });
  }

  handleReload = () => {
    window.location.reload();
  };

  render() {
    if (this.state.hasError) {
      return (
        <div style={{ padding: 40, textAlign: 'center', maxWidth: 700, margin: '40px auto' }}>
          <h2 style={{ color: '#dc2626', marginBottom: 16 }}>Erreur inattendue</h2>
          <pre style={{ textAlign: 'left', background: '#f8f9fa', padding: 16, borderRadius: 8, fontSize: 13, overflow: 'auto', maxHeight: 400, marginBottom: 24, color: '#333' }}>
            {this.state.error?.stack || this.state.error?.message || 'Unknown error'}
          </pre>
          <button className="btn btn-primary" onClick={this.handleReload} style={{ padding: '10px 24px' }}>
            Recharger l'application
          </button>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
