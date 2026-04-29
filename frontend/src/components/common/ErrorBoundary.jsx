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
    console.error('App crash caught by boundary:', error, errorInfo);
  }

  handleReload = () => {
    window.location.reload();
  };

  render() {
    if (this.state.hasError) {
      return (
        <div style={{ padding: 40, textAlign: 'center', maxWidth: 600, margin: '100px auto' }}>
          <h2 style={{ color: '#dc2626', marginBottom: 16 }}>Une erreur inattendue s'est produite</h2>
          <p style={{ color: '#666', marginBottom: 24, fontSize: 16 }}>
            Nous sommes désolés, une erreur technique est survenue. Veuillez recharger l'application.
          </p>
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
