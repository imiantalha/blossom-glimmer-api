import React from "react";

export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false };
    }

    static getDerivedStateFromError() {
        return { hasError: true };
    }

    componentDidCatch(error, info) {
        console.error("[Blossom Glimmer Admin] Dashboard rendering failed:", error, info);
    }

    handleRetry = () => this.setState({ hasError: false });

    render() {
        if (this.state.hasError) {
            return (
                <main className="container py-5" role="alert" aria-live="assertive">
                    <div className="mx-auto text-center" style={{ maxWidth: 560 }}>
                        <h1 className="h3 mb-3">Something went wrong</h1>
                        <p className="text-muted">We couldn’t display this page. Please try again. If the problem continues, return to the dashboard.</p>
                        <button type="button" className="btn btn-primary mt-2" onClick={this.handleRetry}>Try again</button>
                    </div>
                </main>
            );
        }
        return this.props.children;
    }
}
