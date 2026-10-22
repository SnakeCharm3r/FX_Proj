import unittest
from study import candidates, load_bars, outcomes, study


def history(n=40):
    return [dict(time=1790002800+i*3600, open=100, high=101, low=99, close=100, closed=True) for i in range(n)]


class StudyTest(unittest.TestCase):
    def test_bullish_rejection_does_not_require_bearish_candle_color(self):
        bars = history()
        bars[20].update(open=98, low=90, high=101, close=100)
        self.assertEqual('BUY', candidates(bars)[0]['direction'])

    def test_followthrough_is_only_available_after_its_completed_candle(self):
        bars = history()
        bars[20].update(open=98, low=90, high=101, close=100)
        bars[21].update(open=100, low=99, high=104, close=103)
        self.assertFalse(any(e['rule']=='rejection_followthrough' for e in candidates(bars[:21])))
        event = next(e for e in candidates(bars) if e['rule']=='rejection_followthrough')
        self.assertEqual(21, event['signal_index'])
        bars[30].update(close=10000, high=10001)
        self.assertIn(event, candidates(bars))

    def test_entry_uses_next_open_and_final_unresolved_signals_are_censored(self):
        bars = history()
        event = dict(signal_index=20, direction='BUY', atr=2)
        bars[21]['open'] = 100.5
        self.assertEqual(100.5, outcomes(bars, event, 1)['entry_reference'])
        event['signal_index'] = 39
        self.assertIsNone(outcomes(bars, event, 1))

    def test_one_candle_crossing_both_excursions_is_unresolved(self):
        bars = history()
        bars[21].update(low=97, high=105)
        event = dict(signal_index=20, direction='BUY', atr=2)
        self.assertEqual('ambiguous', outcomes(bars, event, 1)['first_excursion'])

    def test_development_outcomes_cannot_leak_across_the_partition(self):
        bars = history()
        bars[25].update(open=98, low=90, high=101, close=100)
        result = study({'candles':bars})
        event = next(e for e in result['events'] if e['rule']=='rejection_only' and e['horizon_bars']==12)
        self.assertTrue(event['embargoed'])
        self.assertIsNone(event['outcome'])

    def test_developing_candle_is_excluded_and_invalid_prices_are_rejected(self):
        bars = history()
        self.assertEqual(39, len(load_bars({'candles':bars[:-1]+[dict(bars[-1],closed=False)]})))
        bars[3]['high'] = 10
        with self.assertRaises(ValueError):
            load_bars({'candles':bars})


if __name__ == '__main__':
    unittest.main()
