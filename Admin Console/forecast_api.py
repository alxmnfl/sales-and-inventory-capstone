"""
Lucky 8 — Demand Forecasting API
Run: python forecast_api.py
Requires: pip install flask flask-cors mysql-connector-python pandas numpy scikit-learn
"""

from flask import Flask, jsonify, request
from flask_cors import CORS
import mysql.connector
import pandas as pd
import numpy as np
from sklearn.linear_model import LinearRegression
from datetime import datetime, timedelta

app = Flask(__name__)
CORS(app)

DB_CONFIG = {
    'host':     'localhost',
    'user':     'root',
    'password': '',
    'database': 'lucky8_db',
}


def get_conn():
    return mysql.connector.connect(**DB_CONFIG)


@app.route('/api/status')
def status():
    return jsonify({'ok': True, 'message': 'Forecast API running'})


@app.route('/api/forecast')
def forecast():
    branch     = request.args.get('branch', '')
    days_ahead = min(int(request.args.get('days', 14)), 60)

    try:
        conn   = get_conn()
        cursor = conn.cursor(dictionary=True)

        sql = """
            SELECT DATE(s.created_at) AS day,
                   SUM(si.quantity)   AS units,
                   SUM(si.total_price) AS revenue
            FROM pos_sale_items si
            JOIN pos_sales s ON si.sale_id = s.id
            WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        """
        params = []
        if branch:
            sql += " AND s.branch = %s"
            params.append(branch)
        sql += " GROUP BY DATE(s.created_at) ORDER BY day"

        cursor.execute(sql, params)
        rows = cursor.fetchall()
        cursor.close()
        conn.close()

        if len(rows) < 3:
            return jsonify({'success': False, 'error': 'Not enough sales data (need at least 3 days).'}), 200

        df = pd.DataFrame(rows)
        df['day'] = pd.to_datetime(df['day'])
        df = df.set_index('day').asfreq('D', fill_value=0)
        df['t'] = range(len(df))

        # Fit models
        X = df[['t']].values
        m_rev   = LinearRegression().fit(X, df['revenue'].values)
        m_units = LinearRegression().fit(X, df['units'].values)

        last_t = int(df['t'].max())
        future_ts    = np.array([[last_t + i + 1] for i in range(days_ahead)])
        future_dates = [(df.index[-1] + timedelta(days=i + 1)).strftime('%Y-%m-%d') for i in range(days_ahead)]

        pred_rev   = [max(0.0, float(v)) for v in m_rev.predict(future_ts)]
        pred_units = [max(0, round(float(v))) for v in m_units.predict(future_ts)]

        historical = [
            {'date': str(idx.date()), 'revenue': float(row['revenue']), 'units': int(row['units'])}
            for idx, row in df.tail(30).iterrows()
        ]

        trend_dir = 'up' if m_rev.coef_[0] > 0 else 'down'
        trend_pct = round(abs(m_rev.coef_[0]) / max(df['revenue'].mean(), 1) * 100, 1)

        return jsonify({
            'success':    True,
            'trend':      trend_dir,
            'trend_pct':  trend_pct,
            'historical': historical,
            'forecast': [
                {'date': d, 'revenue': round(r, 2), 'units': u}
                for d, r, u in zip(future_dates, pred_rev, pred_units)
            ],
        })

    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


@app.route('/api/product_forecast')
def product_forecast():
    branch = request.args.get('branch', '')
    limit  = min(int(request.args.get('limit', 15)), 50)

    try:
        conn   = get_conn()
        cursor = conn.cursor(dictionary=True)

        sql = """
            SELECT si.product_id,
                   si.product_name                 AS name,
                   si.sku,
                   SUM(si.quantity)                AS total_units,
                   SUM(si.quantity) / 30.0         AS avg_daily,
                   p.stock,
                   p.branch
            FROM pos_sale_items si
            JOIN pos_sales s  ON si.sale_id  = s.id
            LEFT JOIN pos_products p ON p.id = si.product_id
            WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        """
        params = []
        if branch:
            sql += " AND s.branch = %s"
            params.append(branch)
        sql += " GROUP BY si.product_id, si.product_name, si.sku, p.stock, p.branch ORDER BY total_units DESC LIMIT %s"
        params.append(limit)

        cursor.execute(sql, params)
        rows = cursor.fetchall()
        cursor.close()
        conn.close()

        results = []
        for row in rows:
            avg_d   = float(row['avg_daily'] or 0)
            stock   = int(row['stock'] or 0)
            days    = round(stock / avg_d) if avg_d > 0 else 999
            risk    = 'critical' if days < 3 else 'high' if days < 7 else 'medium' if days < 14 else 'low'
            results.append({
                'name':         row['name'],
                'sku':          row['sku'],
                'branch':       (row['branch'] or '').upper(),
                'units_30d':    int(row['total_units']),
                'avg_daily':    round(avg_d, 2),
                'stock':        stock,
                'days_to_out':  min(days, 999),
                'risk':         risk,
            })

        return jsonify({'success': True, 'products': results})

    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


if __name__ == '__main__':
    print("Lucky 8 Forecast API starting on http://127.0.0.1:5001")
    print("Install dependencies: pip install flask flask-cors mysql-connector-python pandas numpy scikit-learn")
    app.run(host='127.0.0.1', port=5001, debug=False)
